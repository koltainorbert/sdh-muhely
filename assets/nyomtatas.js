/**
 * SDH Műhely – Nyomtatás (0.52).
 *
 *  - [data-sdh-nyomtat="<id>"] gombok: megnyitják a Nyomtatás ablakot – a
 *    saját szintjük FÖLÉ, így a munkalap-ablak nyitva marad alatta.
 *    data-ny-<név>: a nyomtatvány paraméterei (tipus, datum, tol, ig, szuro,
 *    fajta, minta).
 *  - A Nyomtatás ablak (modul: nyomtatas): nyomtatványváltás, példányszám,
 *    élő előnézet, nyomtatás, mentés PDF-be, küldés e-mailben.
 *  - Felajánlás: új lap mentése és lezárás után (a szerver jegyzi fel, a
 *    böngésző lekéri) – oldalváltás után is megjelenik.
 *  - A sablonszerkesztő (modul: nyomtsablon) élő előnézete és a gyári
 *    beállítás visszaállítása.
 *
 * Egy PDF készül a szerveren; ugyanaz megy az előnézetbe, a nyomtatóra, a
 * letöltésbe és az e-mailbe.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};
    var TAROLO = 'sdh-ny-ajanlat';

    function app() {
        return window.SDH_MUHELY_APP || null;
    }

    function ajaxCim() {
        return new URL(B.ajax || '/wp-admin/admin-ajax.php', window.location.origin);
    }

    function e(szoveg) {
        var d = document.createElement('div');

        d.textContent = String(szoveg == null ? '' : szoveg);

        return d.innerHTML;
    }

    /* ---------------------------------------------------------------- */
    /* PDF lekérése, nyomtatás, letöltés                                 */
    /* ---------------------------------------------------------------- */

    function pdfCim(tipus, param, peldany, mod, nonce) {
        var cim = ajaxCim();

        cim.searchParams.set('action', 'sdh_muhely_nyomtatas_pdf');
        cim.searchParams.set('_wpnonce', nonce || B.nonce || '');
        cim.searchParams.set('tipus', tipus);
        cim.searchParams.set('peldany', String(peldany || 1));

        if (mod) {
            cim.searchParams.set('mod', mod);
        }

        Object.keys(param || {}).forEach(function (k) {
            if (param[k] !== '' && param[k] != null) {
                cim.searchParams.set(k, param[k]);
            }
        });

        return cim.toString();
    }

    /** A PDF mint Blob. torzs: FormData (a sablonszerkesztő előnézeténél), különben GET. */
    function pdfBlob(cim, torzs) {
        return fetch(cim, { method: torzs ? 'POST' : 'GET', body: torzs || undefined, credentials: 'same-origin' })
            .then(function (v) {
                var tipus = v.headers.get('Content-Type') || '';

                if (!v.ok || tipus.indexOf('application/pdf') !== 0) {
                    return v.text().then(function (szoveg) {
                        throw new Error(szoveg && szoveg.length < 300 && szoveg !== '-1' ? szoveg : 'A nyomtatvány nem készült el (lejárt a munkamenet? Frissítsd az oldalt).');
                    });
                }

                return v.blob();
            });
    }

    var nyomtatoKeret = null;

    /**
     * Blob nyomtatása: rejtett keretben megnyitjuk, és a böngésző nyomtatás-ablakát kérjük.
     * Az ígéret értéke: true = a nyomtatás-ablak megnyílt; false = a böngésző nem engedte
     * (ilyenkor a hívó hivatkozást ad a PDF megnyitásához; a cím: nyomtatBlob.utolsoUrl).
     */
    function nyomtatBlob(blob) {
        var url = URL.createObjectURL(blob);

        nyomtatBlob.utolsoUrl = url;

        if (nyomtatoKeret) {
            nyomtatoKeret.remove();
        }

        var keret = document.createElement('iframe');

        keret.className = 'sdh-ny-nyomtato';
        keret.setAttribute('aria-hidden', 'true');
        keret.setAttribute('tabindex', '-1');
        nyomtatoKeret = keret;

        window.setTimeout(function () { URL.revokeObjectURL(url); }, 10 * 60 * 1000);

        return new Promise(function (kesz) {
            var eldolt = false;

            function vege(sikerult) {
                if (!eldolt) {
                    eldolt = true;
                    kesz(sikerult);
                }
            }

            keret.addEventListener('load', function () {
                window.setTimeout(function () {
                    if (eldolt) {
                        return;
                    }

                    try {
                        keret.contentWindow.focus();
                        keret.contentWindow.print();
                        vege(true);
                    } catch (hiba) {
                        vege(false);
                    }
                }, 350);
            });

            // Ha a keret be sem tölt (telefon, kikapcsolt PDF-néző), ne maradjon csendben.
            window.setTimeout(function () { vege(false); }, 6000);

            keret.src = url;
            document.body.appendChild(keret);
        });
    }

    function letolt(cim) {
        var a = document.createElement('a');

        a.href = cim;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
    }

    /** Nyomtatás ablak nélkül (a felajánlás gombja). Az ígéret értéke, mint a nyomtatBlob-nál. */
    function nyomtatKozvetlen(tipus, param, peldany) {
        // nyomtat=1: a szerver a lap Naplójába írja, hogy nyomtatásra ment (0.59).
        return pdfBlob(pdfCim(tipus, param, peldany) + '&nyomtat=1').then(nyomtatBlob);
    }

    /* ---------------------------------------------------------------- */
    /* A Nyomtatás ablak megnyitása                                      */
    /* ---------------------------------------------------------------- */

    function ablakNyit(id, param, indito) {
        var a = app();

        if (!a) {
            return;
        }

        var szint = indito && a.sajatSzint ? a.sajatSzint(indito) : -1;

        // Ha nyitva van egy popup (pl. a munkalap), és a gomb nem abból jött, akkor is fölé nyitunk.
        if (szint < 0) {
            var nyitott = document.querySelectorAll('dialog.sdh-modal[open]').length;

            szint = nyitott - 1;
        }

        a.nyit('nyomtatas', String(id || 0), { szint: Math.min(szint + 1, 3), parameterek: param || {} });
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-nyomtat]') : null;

        if (!gomb) {
            return;
        }

        esemeny.preventDefault();

        var param = {};

        Object.keys(gomb.dataset).forEach(function (k) {
            if (k.indexOf('ny') === 0 && k.length > 2) {
                param[k.charAt(2).toLowerCase() + k.slice(3)] = gomb.dataset[k];
            }
        });

        var id = parseInt(gomb.getAttribute('data-sdh-nyomtat'), 10) || 0;

        // A munkalap űrlapjában: az azonosító az űrlapból jön (új lapnál még nincs mit nyomtatni).
        if (!id && gomb.hasAttribute('data-ny-urlapbol')) {
            var mezo = (gomb.closest('form') || document).querySelector('input[name="id"]');

            id = mezo ? parseInt(mezo.value, 10) || 0 : 0;
            delete param.urlapbol;
        }

        ablakNyit(id, param, gomb);
    });

    /* ---------------------------------------------------------------- */
    /* A Nyomtatás ablak működése                                        */
    /* ---------------------------------------------------------------- */

    function ablakIndul(torzs) {
        var gy = torzs.querySelector('[data-sdh-ny]');

        if (!gy || gy.sdhKesz) {
            return;
        }

        gy.sdhKesz = true;

        var adat = {};

        try {
            adat = JSON.parse(gy.getAttribute('data-adat') || '{}');
        } catch (hiba) {
            adat = {};
        }

        var nonce = gy.getAttribute('data-nonce') || B.nonce || '';
        var tipus = gy.getAttribute('data-tipus');
        var keret = gy.querySelector('[data-ny-keret]');
        var toltes = gy.querySelector('[data-ny-toltes]');
        var uzenet = gy.querySelector('[data-ny-uzenet]');
        var peldanyMezo = gy.querySelector('[data-ny-peldany]');
        var emailUrlap = gy.querySelector('[data-ny-email]');
        var emailGomb = gy.querySelector('[data-ny-email-nyit]');
        var elozoUrl = '';
        var elonezetBlob = null;
        var sorszam = 0;
        var ido = 0;
        var emailAtirt = { cimzett: false, targy: false, szoveg: false };

        function fajta() {
            return (adat[tipus] || {}).adat || '';
        }

        function jelez(szoveg, osztaly) {
            uzenet.textContent = szoveg || '';
            uzenet.className = 'sdh-ny__uzenet' + (osztaly ? ' is-' + osztaly : '');
        }

        function peldany() {
            return Math.min(9, Math.max(1, parseInt(peldanyMezo.value, 10) || 1));
        }

        /** A most érvényes paraméterek: csak azok a mezők, amelyek ehhez a nyomtatványhoz tartoznak. */
        function param() {
            var p = {};
            var id = parseInt(gy.getAttribute('data-id'), 10) || 0;

            if (id) {
                p.id = id;
            }

            if (gy.getAttribute('data-minta') === '1') {
                p.minta = 1;
            }

            Array.prototype.forEach.call(gy.querySelectorAll('[data-ny-param]'), function (mezo) {
                var doboz = mezo.closest('[data-ny-csak]');
                var csak = mezo.getAttribute('data-ny-param-csak');

                if ((doboz && doboz.hidden) || (csak && csak !== fajta())) {
                    return;
                }

                p[mezo.getAttribute('data-ny-param')] = mezo.value;
            });

            return p;
        }

        var ujLap = gy.querySelector('[data-ny-ujlap]');

        function elonezet() {
            var en = ++sorszam;

            if (ujLap) {
                ujLap.href = pdfCim(tipus, param(), 1, '', nonce);
            }

            toltes.hidden = false;
            toltes.textContent = 'Az előnézet készül…';
            toltes.classList.remove('is-hiba');
            elonezetBlob = null;

            pdfBlob(pdfCim(tipus, param(), 1, '', nonce)).then(function (blob) {
                if (en !== sorszam) {
                    return;
                }

                if (elozoUrl) {
                    URL.revokeObjectURL(elozoUrl);
                }

                elonezetBlob = blob;
                elozoUrl = URL.createObjectURL(blob);
                keret.src = elozoUrl + '#toolbar=0&navpanes=0&view=FitH';
                keret.hidden = false;
                toltes.hidden = true;
            }).catch(function (hiba) {
                if (en !== sorszam) {
                    return;
                }

                keret.hidden = true;
                toltes.hidden = false;
                toltes.classList.add('is-hiba');
                toltes.textContent = hiba.message;
            });
        }

        function frissit(azonnal) {
            // A régi előnézet már nem érvényes: ha most nyomtatnak, friss PDF készül.
            elonezetBlob = null;
            window.clearTimeout(ido);
            ido = window.setTimeout(elonezet, azonnal ? 0 : 350);
        }

        function tipusValt(uj, elso) {
            tipus = uj;

            Array.prototype.forEach.call(gy.querySelectorAll('[data-ny-tipus]'), function (g) {
                var aktiv = g.getAttribute('data-ny-tipus') === uj;

                g.classList.toggle('is-aktiv', aktiv);
                g.setAttribute('aria-selected', aktiv ? 'true' : 'false');
            });

            Array.prototype.forEach.call(gy.querySelectorAll('[data-ny-csak]'), function (doboz) {
                doboz.hidden = doboz.getAttribute('data-ny-csak').split(' ').indexOf(fajta()) < 0;
            });

            if (!elso) {
                peldanyMezo.value = (adat[uj] || {}).peldany || 1;
            }

            // Az e-mail tárgya és szövege a nyomtatványé – amíg kézzel át nem írták.
            if (emailUrlap) {
                // A címzett is a nyomtatványé (belső papírnál üres) – amíg kézzel át nem írták.
                if (!emailAtirt.cimzett) {
                    emailUrlap.querySelector('[name="cimzett"]').value = (adat[uj] || {}).cimzett || '';
                }

                if (!emailAtirt.targy) {
                    emailUrlap.querySelector('[name="targy"]').value = (adat[uj] || {}).targy || '';
                }

                if (!emailAtirt.szoveg) {
                    emailUrlap.querySelector('[name="szoveg"]').value = (adat[uj] || {}).szoveg || '';
                }
            }

            jelez('');
            frissit(true);
        }

        gy.addEventListener('click', function (esemeny) {
            var cel = esemeny.target;
            var g;

            if ((g = cel.closest('[data-ny-tipus]'))) {
                if (g.getAttribute('data-ny-tipus') !== tipus) {
                    tipusValt(g.getAttribute('data-ny-tipus'));
                }

                return;
            }

            if ((g = cel.closest('[data-ny-lep]'))) {
                peldanyMezo.value = Math.min(9, Math.max(1, peldany() + parseInt(g.getAttribute('data-ny-lep'), 10)));

                return;
            }

            if ((g = cel.closest('[data-ny-nyomtat]'))) {
                var n = peldany();

                g.disabled = true;
                jelez('A nyomtatvány készül…');

                // A már betöltött előnézetnél külön jelezzük a nyomtatást a Naplónak (0.59).
                if (n === 1 && elonezetBlob) {
                    fetch(pdfCim(tipus, param(), 1, 'naplo', nonce) + '&nyomtat=1', { credentials: 'same-origin' }).catch(function () {});
                }

                (n === 1 && elonezetBlob ? Promise.resolve(elonezetBlob) : pdfBlob(pdfCim(tipus, param(), n, '', nonce) + '&nyomtat=1'))
                    .then(nyomtatBlob)
                    .then(function (megnyilt) {
                        if (megnyilt) {
                            jelez(n > 1 ? n + ' példány megy a nyomtatóra – a böngésző nyomtatás-ablakában egy példányt állíts be.' : 'Megnyílt a böngésző nyomtatás-ablaka.', 'siker');

                            return;
                        }

                        // A böngésző nem engedte a keretből: hivatkozás a PDF-hez, onnan a saját nyomtatás gombjával megy.
                        uzenet.className = 'sdh-ny__uzenet is-hiba';
                        uzenet.innerHTML = 'A böngésző innen nem tud nyomtatni. <a href="' + e(nyomtatBlob.utolsoUrl) + '" target="_blank" rel="noopener">Nyisd meg a PDF-et új lapon</a>, és onnan nyomtasd ki.';
                    })
                    .catch(function (hiba) { jelez(hiba.message, 'hiba'); })
                    .then(function () { g.disabled = false; });

                return;
            }

            if (cel.closest('[data-ny-ment]')) {
                letolt(pdfCim(tipus, param(), peldany(), 'letolt', nonce));
                jelez('A PDF letöltése elindult.', 'siker');

                return;
            }

            if ((g = cel.closest('[data-ny-email-nyit]'))) {
                emailUrlap.hidden = !emailUrlap.hidden;
                g.setAttribute('aria-expanded', emailUrlap.hidden ? 'false' : 'true');

                if (!emailUrlap.hidden) {
                    var cimzett = emailUrlap.querySelector('[name="cimzett"]');

                    (cimzett.value ? emailUrlap.querySelector('[name="szoveg"]') : cimzett).focus();
                }
            }
        });

        peldanyMezo.addEventListener('change', function () {
            peldanyMezo.value = peldany();
        });

        gy.addEventListener('change', function (esemeny) {
            if (esemeny.target.closest && esemeny.target.closest('[data-ny-param]')) {
                frissit();
            }
        });

        if (emailUrlap) {
            emailUrlap.addEventListener('input', function (esemeny) {
                var nev = esemeny.target.getAttribute('name');

                if (nev === 'targy' || nev === 'szoveg' || nev === 'cimzett') {
                    emailAtirt[nev] = true;
                }
            });

            emailUrlap.querySelector('[data-ny-kuld]').addEventListener('click', function () {
                var gomb = this;
                var torzs2 = new FormData();
                var p = param();

                Array.prototype.forEach.call(emailUrlap.querySelectorAll('[name]'), function (mezo) {
                    torzs2.set(mezo.getAttribute('name'), mezo.value);
                });

                torzs2.set('action', 'sdh_muhely_nyomtatas_email');
                torzs2.set('_wpnonce', nonce);
                torzs2.set('tipus', tipus);
                Object.keys(p).forEach(function (k) { torzs2.set(k, p[k]); });

                gomb.disabled = true;
                jelez('Küldés…');

                fetch(ajaxCim().toString(), { method: 'POST', body: torzs2, credentials: 'same-origin' })
                    .then(function (v) { return v.json(); })
                    .then(function (v) {
                        if (!v || !v.success) {
                            throw new Error((v && v.data && v.data.uzenet) || 'A levél nem ment el.');
                        }

                        jelez(v.data.uzenet, 'siker');
                        emailUrlap.hidden = true;
                        emailGomb.setAttribute('aria-expanded', 'false');
                    })
                    .catch(function (hiba) { jelez(hiba.message, 'hiba'); })
                    .then(function () { gomb.disabled = false; });
            });
        }

        // Az ablakban nincs űrlap, ezért a Bezárás gombot itt kötjük be (az app.js csak űrlapos popupnál teszi).
        var bezaro = torzs.querySelector('[data-sdh-megsem]');

        if (bezaro) {
            bezaro.addEventListener('click', function (esemeny) {
                esemeny.preventDefault();

                var a = app();

                if (a && a.bezar) {
                    a.bezar(Math.max(0, a.sajatSzint(bezaro)));
                }
            });
        }

        // „Az oldal nyomtatása, ahogy látszik": az ablak bezárul, és a böngésző a mögötte lévő oldalt nyomtatja.
        var kepernyo = torzs.querySelector('[data-ny-kepernyo]');

        if (kepernyo) {
            kepernyo.addEventListener('click', function () {
                var a = app();

                if (a && a.bezar) {
                    a.bezar(Math.max(0, a.sajatSzint(kepernyo)));
                }

                window.setTimeout(function () { window.print(); }, 350);
            });
        }

        // A munkalapon nem mentett módosítás van? A nyomtatvány a mentett állapotot mutatja – szólunk.
        var megj = gy.querySelector('.sdh-ny__megj');
        var alatta = document.querySelector('dialog.sdh-modal[open] form[data-sdh-ajax-action$="_munkalapok_ment"]');

        if (megj && alatta && alatta.sdhNyPiszkos) {
            megj.innerHTML = '<strong>Figyelem:</strong> a munkalapon nem mentett módosítás van. A nyomtatvány a legutóbb <strong>elmentett</strong> állapotot mutatja – előbb mentsd a lapot, ha a változás is kell rá.';
            megj.classList.add('is-figyelem');
        }

        tipusValt(tipus, true);
    }

    // A munkalap-űrlap „piszkos" jelzője: változott-e valami a megnyitás óta.
    document.addEventListener('input', function (esemeny) {
        var urlap = esemeny.target.closest ? esemeny.target.closest('form[data-sdh-ajax-action$="_munkalapok_ment"]') : null;

        if (urlap) {
            urlap.sdhNyPiszkos = true;
        }
    });

    /* ---------------------------------------------------------------- */
    /* Sablonszerkesztő: élő előnézet, gyári beállítás                   */
    /* ---------------------------------------------------------------- */

    function szerkesztoIndul(torzs) {
        var urlap = torzs.querySelector('[data-sdh-ny-szerk]');

        if (!urlap || urlap.sdhKesz) {
            return;
        }

        urlap.sdhKesz = true;

        var tipus = urlap.getAttribute('data-tipus');
        var keret = urlap.querySelector('[data-ny-keret]');
        var toltes = urlap.querySelector('[data-ny-toltes]');
        var nonce = (urlap.querySelector('[name="_wpnonce"]') || {}).value || B.nonce || '';
        var sorszam = 0;
        var ido = 0;
        var elozoUrl = '';

        function elonezet() {
            if (!keret) {
                return;
            }

            var en = ++sorszam;
            var adat = new FormData(urlap);

            adat.set('sablon_proba', '1');
            adat.set('minta', '1');

            pdfBlob(pdfCim(tipus, { minta: 1 }, 1, '', nonce), adat).then(function (blob) {
                if (en !== sorszam) {
                    return;
                }

                if (elozoUrl) {
                    URL.revokeObjectURL(elozoUrl);
                }

                elozoUrl = URL.createObjectURL(blob);
                keret.src = elozoUrl + '#toolbar=0&navpanes=0&view=FitH';
                keret.hidden = false;
                toltes.hidden = true;
            }).catch(function (hiba) {
                if (en !== sorszam) {
                    return;
                }

                toltes.hidden = false;
                toltes.classList.add('is-hiba');
                toltes.textContent = hiba.message;
            });
        }

        function frissit() {
            window.clearTimeout(ido);
            ido = window.setTimeout(elonezet, 600);
        }

        urlap.addEventListener('input', frissit);
        urlap.addEventListener('change', frissit);

        var alap = urlap.querySelector('[data-ny-alap]');

        if (alap) {
            alap.addEventListener('click', function () {
                // Kétlépcsős gomb: az első kattintás csak rákérdez.
                if (alap.getAttribute('data-lepes') !== '1') {
                    alap.setAttribute('data-lepes', '1');
                    alap.textContent = 'Biztosan? Kattints újra – a saját szövegek is elvesznek';
                    alap.classList.add('is-veszely');

                    window.setTimeout(function () {
                        alap.setAttribute('data-lepes', '0');
                        alap.textContent = 'Gyári beállítás visszaállítása';
                        alap.classList.remove('is-veszely');
                    }, 5000);

                    return;
                }

                var adat = new FormData();

                adat.set('action', 'sdh_muhely_nyomtsablon_alap');
                adat.set('_wpnonce', nonce);
                adat.set('tipus', tipus);
                adat.set('kontextus', B.kontextus || 'admin');
                alap.disabled = true;

                fetch(ajaxCim().toString(), { method: 'POST', body: adat, credentials: 'same-origin' })
                    .then(function (v) { return v.json(); })
                    .then(function (v) {
                        if (v && v.success && v.data && v.data.vissza) {
                            window.location.href = v.data.vissza;
                        } else {
                            alap.disabled = false;
                        }
                    })
                    .catch(function () { alap.disabled = false; });
            });
        }

        elonezet();
    }

    document.addEventListener('sdh:urlap-betoltve', function (esemeny) {
        var d = esemeny.detail || {};

        if (d.modul === 'nyomtatas' && d.torzs) {
            ablakIndul(d.torzs);
        } else if (d.modul === 'nyomtsablon' && d.torzs) {
            szerkesztoIndul(d.torzs);
        }
    });

    /* ---------------------------------------------------------------- */
    /* Felajánlás: új lap és lezárás után                                */
    /* ---------------------------------------------------------------- */

    var ajanlatAblak = null;

    function tarolt() {
        try {
            var a = JSON.parse(window.sessionStorage.getItem(TAROLO) || 'null');

            // Három percnél régebbi ajánlat már nem időszerű.
            return a && a.ido && Date.now() - a.ido < 180000 ? a : null;
        } catch (hiba) {
            return null;
        }
    }

    function tarol(a) {
        try {
            if (a) {
                window.sessionStorage.setItem(TAROLO, JSON.stringify(a));
            } else {
                window.sessionStorage.removeItem(TAROLO);
            }
        } catch (hiba) { /* privát ablak: az ajánlat akkor csak oldalváltásig él */ }
    }

    function szamlaElerheto() {
        return !!(window.SDH_MUHELY_APP && typeof window.SDH_MUHELY_APP.szamla === 'function');
    }

    function ajanlatMutat(a) {
        if (ajanlatAblak || !a) {
            return;
        }

        // A pénztár ajánlata és a fizetés ablaka előbb: megvárjuk, amíg bezárul.
        if (document.querySelector('dialog.sdh-pt-ablak[open]')) {
            window.setTimeout(function () { ajanlatMutat(tarolt()); }, 600);

            return;
        }

        var d = document.createElement('dialog');
        var tarsak = (a.tarsak || []).slice(0, 3);

        function nyomtatGomb(tipus, cim, peldany, fo) {
            return '<button type="button" class="sdh-gomb ' + (fo ? 'sdh-gomb--elsodleges' : 'sdh-gomb--vilagos') + '"' +
                ' data-nya="nyomtat" data-tipus="' + e(tipus) + '" data-peldany="' + (peldany || 1) + '"' +
                ' data-felirat="' + e(cim) + (peldany > 1 ? ' (' + peldany + ' példány)' : '') + '">' +
                e(cim) + (peldany > 1 ? ' (' + peldany + ' példány)' : '') + '</button>';
        }

        d.className = 'sdh-ny-ajanlat';
        d.setAttribute('aria-label', 'Nyomtatás felajánlása');
        d.innerHTML =
            '<div class="sdh-ny-ajanlat__ikon"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5.5 7V3h9v4"/><rect x="3" y="7" width="14" height="7" rx="1.6"/><path d="M5.5 12h9v5h-9z"/></svg></div>' +
            '<div class="sdh-ny-ajanlat__szoveg"><strong>' + e(a.cim) + (a.szam ? ' – ' + e(a.szam) : '') + '</strong><span>' + e(a.szoveg) + '</span>' +
            '<span class="sdh-ny-ajanlat__hiba" data-nya-hiba aria-live="polite"></span></div>' +
            '<div class="sdh-ny-ajanlat__gombok">' +
            // A lap ajánlott papírja, utána a többi (munkalap, átadási bizonylat…): az ablak
            // nyitva marad, így egymás után több is nyomtatható (0.60.0).
            nyomtatGomb(a.tipus, 'Nyomtatás: ' + a.cim, a.peldany || 1, true) +
            tarsak.map(function (t) { return nyomtatGomb(t.tipus, 'Nyomtatás: ' + t.cim, t.peldany || 1, false); }).join('') +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-nya="megnyit">Előnézet, e-mail…</button>' +
            // A számla is innen indul (Számlázz.hu és a helyi Számla-előkészítő).
            (a.szamla && a.szamla.fo && szamlaElerheto() ? '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-ny-ajanlat__szamla" data-nya="szamla-fo">Számlázz.hu számla</button>' : '') +
            (a.szamla && a.szamla.helyi && szamlaElerheto() ? '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-ny-ajanlat__szamla" data-nya="szamla-helyi">Számla-előkészítő (PDF)</button>' : '') +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-ny-ajanlat__kesz" data-nya="nem">Most nem</button>' +
            '</div>';

        document.body.appendChild(d);
        ajanlatAblak = d;

        function zar() {
            tarol(null);
            ajanlatAblak = null;

            if (d.open) {
                d.close();
            }

            d.remove();
        }

        /** Egy gomb lefutott: megjelöljük, de az ablak marad – jöhet a következő papír. */
        function kesz(g) {
            g.classList.add('is-kesz');
            g.disabled = false;
            g.textContent = (g.getAttribute('data-felirat') || g.textContent).replace(/^(Kész – )?/, 'Kész – ');

            var bezaro = d.querySelector('.sdh-ny-ajanlat__kesz');

            if (bezaro) {
                bezaro.textContent = 'Bezárás';
                bezaro.classList.add('sdh-gomb--elsodleges');
            }
        }

        d.addEventListener('cancel', function (esemeny) {
            esemeny.preventDefault();
            zar();
        });

        d.addEventListener('click', function (esemeny) {
            var g = esemeny.target.closest ? esemeny.target.closest('[data-nya]') : null;

            if (!g) {
                return;
            }

            var mit = g.getAttribute('data-nya');

            // Csak a „Most nem" / „Bezárás" zárja be az ablakot: a lezárás után
            // munkalap és számla is kellhet, ezért a felajánlás nyitva marad (0.60.0).
            if (mit === 'nem') {
                zar();
            } else if (mit === 'szamla-fo' || mit === 'szamla-helyi') {
                kesz(g);
                window.SDH_MUHELY_APP.szamla(a.id, mit === 'szamla-fo' ? 'fo' : 'helyi');
            } else if (mit === 'megnyit') {
                ablakNyit(a.id, { tipus: a.tipus }, null);
            } else {
                var tipus = g.getAttribute('data-tipus') || a.tipus;
                var peldany = parseInt(g.getAttribute('data-peldany'), 10) || 1;
                var felirat = g.getAttribute('data-felirat') || g.textContent;

                g.disabled = true;
                g.textContent = 'Készül…';

                nyomtatKozvetlen(tipus, { id: a.id }, peldany).then(function (megnyilt) {
                    kesz(g);

                    // Ha a böngésző innen nem tud nyomtatni, a Nyomtatás ablak nyílik meg (előnézet, mentés, új lap).
                    if (!megnyilt) {
                        ablakNyit(a.id, { tipus: tipus }, null);
                    }
                }).catch(function (hiba) {
                    g.disabled = false;
                    g.textContent = felirat;
                    d.querySelector('[data-nya-hiba]').textContent = hiba.message;
                });
            }
        });

        d.showModal();
        d.querySelector('[data-nya="nyomtat"]').focus();

        // Megmutattuk: a szerver most már elfelejtheti (a böngésző a saját tárolójából tudja).
        ajanlatNyugta();
    }

    function ajanlatNyugta() {
        var cim = ajaxCim();

        cim.searchParams.set('action', 'sdh_muhely_nyomtatas_ajanlat');
        cim.searchParams.set('_wpnonce', B.nonce || '');
        cim.searchParams.set('nyugta', '1');

        fetch(cim.toString(), { credentials: 'same-origin', keepalive: true }).catch(function () { /* legközelebb újra megjelenik – nem baj */ });
    }

    var kerFut = false;

    /** Van-e a szerveren friss ajánlat (új lap / lezárás)? Ha igen, eltesszük és megmutatjuk. */
    function ajanlatKer() {
        if (kerFut) {
            return;
        }

        kerFut = true;

        var cim = ajaxCim();

        cim.searchParams.set('action', 'sdh_muhely_nyomtatas_ajanlat');
        cim.searchParams.set('_wpnonce', B.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                var a = v && v.success && v.data ? v.data.ajanlat : null;

                if (a) {
                    a.ido = Date.now();
                    // Eltesszük: ha a mentés után az oldal újratölt, az ajánlat utána is megjelenik.
                    tarol(a);
                    window.setTimeout(function () { ajanlatMutat(tarolt()); }, 450);
                }
            })
            .catch(function () { /* az ajánlat kényelmi szolgáltatás: hiba esetén csendben kimarad */ })
            .then(function () { kerFut = false; });
    }

    // Mentés, állapotváltás, fizetés után: lehet, hogy most keletkezett ajánlat.
    // Automatikus mentés után csak akkor, ha a szerver jelzi, hogy most keletkezett ajánlat (pl. lezárás).
    document.addEventListener('sdh:automentve', function (ev) {
        if (ev.detail && ev.detail.adat && ev.detail.adat.nyomtatas_ajanlat) {
            window.setTimeout(ajanlatKer, 150);
        }
    });

    ['sdh:mentve', 'sdh:allapot', 'sdh:penztar-valtozott'].forEach(function (nev) {
        document.addEventListener(nev, function () {
            window.setTimeout(ajanlatKer, 150);
        });
    });

    function indul() {
        var a = tarolt();

        if (a) {
            window.setTimeout(function () { ajanlatMutat(tarolt()); }, 500);
        } else if (B.nyomtat && B.nyomtat.ajanlat) {
            ajanlatKer();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', indul);
    } else {
        indul();
    }

    window.SDH_NYOMTATAS = {
        nyit: ablakNyit,
        nyomtat: nyomtatKozvetlen,
        ajanlatKer: ajanlatKer
    };
}());
