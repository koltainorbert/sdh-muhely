/**
 * SDH Műhely – Levelezés (0.32).
 *
 * Két része van:
 *  1. Jelzés MINDEN CRM-oldalon: percenként megkérdezi a szervert, jött-e új
 *     levél. Frissíti az oldalmenü jelvényét, felugró értesítést mutat, és
 *     (ha engedélyezted) a böngésző értesítését is kéri. Amit a fontos-levél
 *     ügynök azonnalinak ítélt, az kiemelten jelenik meg, és nem tűnik el magától.
 *  2. A levelező oldal ([data-sdh-level]): mappák – levéllista – olvasó.
 *     A levélíró, a mappaválasztó az app.js popupjait használja
 *     (window.SDH_MUHELY_APP), így ugyanúgy méretezhető, mint a többi ablak.
 *
 * A levelek tartalma mindig szövegként kerül a lapra (szovegBiztonsagos), a
 * levél HTML-je pedig szkript nélküli, elzárt keretben (iframe sandbox) jelenik meg.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};

    if (!B.ajax) {
        return;
    }

    function app() {
        return window.SDH_MUHELY_APP || {};
    }

    /** Szöveg HTML-be: a levelek adatai (feladó, tárgy…) idegen tartalom. */
    function e(szoveg) {
        return String(szoveg === null || szoveg === undefined ? '' : szoveg)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function kuld(akcio, mezok) {
        var adat = new FormData();

        adat.set('action', 'sdh_muhely_level_' + akcio);
        adat.set('_wpnonce', B.nonce || '');

        Object.keys(mezok || {}).forEach(function (nev) {
            // Tömb: több érték ugyanazon a néven (nev[]).
            if (Array.isArray(mezok[nev])) {
                mezok[nev].forEach(function (ertek) { adat.append(nev + '[]', ertek); });
            } else {
                adat.set(nev, mezok[nev]);
            }
        });

        return fetch(new URL(B.ajax, window.location.origin).toString(), { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (valasz) {
                return valasz.json().catch(function () {
                    throw new Error('A szerver nem várt választ adott (' + valasz.status + ').');
                });
            })
            .then(function (json) {
                if (!json || !json.success) {
                    var hiba = new Error((json && json.data && json.data.uzenet) || 'A művelet nem sikerült.');

                    hiba.adat = (json && json.data) || {};

                    throw hiba;
                }

                return json.data;
            });
    }

    var SZINT = { azonnal: 'Azonnal', ma: 'Ma', raer: 'Ráér', zaj: 'Zaj' };

    /** Lapozó nyilak: rajz, nem betű – így pontosan a gomb közepén ülnek. */
    var NYIL_BAL = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>';
    var NYIL_JOBB = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg>';

    /* ================================================================ */
    /* 1. Jelzés minden oldalon                                         */
    /* ================================================================ */

    var UTOLSO_KULCS = 'sdh-level-utolso';
    var hangKornyezet = null;

    function tarol(kulcs, ertek) {
        try {
            if (ertek === undefined) {
                return window.localStorage.getItem(kulcs);
            }

            window.localStorage.setItem(kulcs, ertek);
        } catch (hiba) {
            /* privát mód: a jelzés akkor is megy, csak nem jegyzi meg, hol tartott */
        }

        return null;
    }

    function jelveny(db, azonnal) {
        // Az Áttekintés csempéje is él: olvasatlan levelek és azonnali teendők.
        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-kartya="levelezes"]'), function (kartya) {
            var szam = kartya.querySelector('.sdh-kartya__szam');
            var jel = kartya.querySelector('[data-sdh-kartya-azonnal]');

            if (szam) {
                szam.textContent = String(db);
            }

            if (jel) {
                jel.textContent = azonnal + ' azonnali';
                jel.hidden = !(azonnal > 0);
            }

            kartya.classList.toggle('sdh-kartya--jelez', azonnal > 0);
        });

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-jelveny="levelezes"]'), function (jel) {
            var volt = parseInt(jel.getAttribute('data-db') || jel.textContent, 10) || 0;

            jel.setAttribute('data-db', String(db));

            // Nagy számnál rövid felirat (a pontos szám a súgóban): a jelvény ne nyomja szét a menüt.
            jel.textContent = db > 999 ? '999+' : String(db);
            jel.hidden = !(db > 0);
            jel.title = db + ' olvasatlan levél' + (azonnal > 0 ? ' · ' + azonnal + ' azonnali teendő' : '');
            jel.classList.toggle('is-azonnal', azonnal > 0);

            if (db > volt) {
                jel.classList.remove('is-friss');
                void jel.offsetWidth;
                jel.classList.add('is-friss');
            }
        });
    }

    function hang() {
        try {
            var Kornyezet = window.AudioContext || window.webkitAudioContext;

            hangKornyezet = hangKornyezet || new Kornyezet();

            [880, 1175].forEach(function (frekvencia, i) {
                var o = hangKornyezet.createOscillator();
                var g = hangKornyezet.createGain();
                var t = hangKornyezet.currentTime + i * 0.16;

                o.type = 'sine';
                o.frequency.value = frekvencia;
                g.gain.setValueAtTime(0.0001, t);
                g.gain.exponentialRampToValueAtTime(0.12, t + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t + 0.22);
                o.connect(g);
                g.connect(hangKornyezet.destination);
                o.start(t);
                o.stop(t + 0.25);
            });
        } catch (hiba) {
            /* a böngésző nem enged hangot kattintás előtt – a látható jelzés megvan */
        }
    }

    var TOAST_MAX = 4;
    var toastTobb = 0;         // ennyi jelzés nem fért ki (összesítve látszik)

    function toastTarto() {
        var tarto = document.querySelector('.sdh-level-toastok');

        if (!tarto) {
            tarto = document.createElement('div');
            tarto.className = 'sdh-level-toastok';
            tarto.setAttribute('aria-live', 'polite');
            document.body.appendChild(tarto);
        }

        return tarto;
    }

    /** Az összesítő sor: „+N további új levél". Egyszerre csak néhány jelzés látszik, a többi itt számolódik. */
    function toastOsszesito() {
        var tarto = toastTarto();
        var sor = tarto.querySelector('[data-sdh-level-tobb]');

        if (toastTobb <= 0) {
            if (sor) {
                sor.remove();
            }

            return;
        }

        if (!sor) {
            sor = document.createElement('div');
            sor.className = 'sdh-level-toast sdh-level-toast--tobb';
            sor.setAttribute('data-sdh-level-tobb', '');
            sor.addEventListener('click', function (esemeny) {
                var mindet = !!esemeny.target.closest('[data-sdh-level-mindzar]');

                toastTobb = 0;

                if (mindet) {
                    Array.prototype.forEach.call(tarto.querySelectorAll('.sdh-level-toast'), function (t) { t.remove(); });

                    return;
                }

                sor.remove();
                window.location.href = B.levelUrl || '';
            });
        }

        sor.innerHTML = '<strong class="sdh-level-toast__cim">+' + toastTobb + ' további új levél</strong>' +
            '<span class="sdh-level-toast__megj">Megnyitás a Levelezésben</span>' +
            '<button type="button" class="sdh-level-toast__mind" data-sdh-level-mindzar>Összes bezárása</button>';

        // Mindig legfelül áll, a jelzések alatta sorakoznak.
        tarto.insertBefore(sor, tarto.firstChild);
    }

    /**
     * Felugró jelzés a jobb alsó sarokban. `maradjon`: nem tűnik el magától (azonnali teendő).
     * Egyszerre legfeljebb TOAST_MAX látszik: ha több jön, a legrégebbi nem azonnali kerül az összesítőbe,
     * így a jelzések soha nem lógnak ki a képernyőről.
     */
    function toast(o) {
        var tarto = toastTarto();
        var doboz = document.createElement('div');

        doboz.className = 'sdh-level-toast' + (o.szint ? ' sdh-level-toast--' + o.szint : '');
        doboz.setAttribute('role', o.szint === 'azonnal' ? 'alert' : 'status');
        doboz.setAttribute('data-sdh-level-toast', o.id || '');
        doboz.innerHTML =
            '<button type="button" class="sdh-level-toast__bezar" aria-label="Bezárás">&times;</button>' +
            (o.cimke ? '<span class="sdh-level-jel sdh-level-jel--' + e(o.szint || 'ma') + '">' + e(o.cimke) + '</span>' : '') +
            // A fiók neve a feladó sorában áll (nem külön sorban), így a jelzés nem lesz magasabb.
            '<span class="sdh-level-toast__sor"><strong class="sdh-level-toast__cim">' + e(o.cim) + '</strong>' +
            (o.fiok ? '<span class="sdh-level-toast__fiok" title="Ebbe a fiókba érkezett">' + e(o.fiok) + '</span>' : '') + '</span>' +
            (o.szoveg ? '<span class="sdh-level-toast__szoveg">' + e(o.szoveg) + '</span>' : '') +
            (o.megj ? '<span class="sdh-level-toast__megj">' + e(o.megj) + '</span>' : '');

        doboz.addEventListener('click', function (esemeny) {
            if (!esemeny.target.closest('.sdh-level-toast__bezar') && o.kattintas) {
                o.kattintas();
            }

            doboz.remove();
        });

        tarto.appendChild(doboz);

        var latszik = Array.prototype.slice.call(tarto.querySelectorAll('.sdh-level-toast:not([data-sdh-level-tobb])'));

        while (latszik.length > TOAST_MAX) {
            // Előbb a nem azonnali jelzések közül a legrégebbi megy az összesítőbe; ha mind azonnali, a legrégebbi.
            var kieso = latszik.filter(function (t) { return !t.classList.contains('sdh-level-toast--azonnal') && t !== doboz; })[0] ||
                latszik.filter(function (t) { return t !== doboz; })[0];

            kieso.remove();
            latszik.splice(latszik.indexOf(kieso), 1);
            toastTobb += 1;
        }

        toastOsszesito();

        if (!o.maradjon) {
            window.setTimeout(function () { doboz.remove(); }, 12000);
        }

        return doboz;
    }

    function levelhez(id) {
        var gyoker = document.querySelector('[data-sdh-level]');

        if (gyoker && typeof gyoker.sdhMegnyit === 'function') {
            gyoker.sdhMegnyit(id);

            return;
        }

        window.location.href = (B.levelUrl || '') + '#level=' + id;
    }

    var fiokNevek = {};     // fiókkulcs → név: az értesítés megmondja, melyik címre jött a levél

    function ertesit(level) {
        var azonnal = level.fontossag === 'azonnal';
        var hova = Object.keys(fiokNevek).length > 1 ? (fiokNevek[level.fiok] || '') : '';

        toast({
            id: level.id,
            fiok: hova,
            szint: azonnal ? 'azonnal' : (level.fontossag === 'ma' ? 'ma' : ''),
            cimke: azonnal ? 'Azonnal reagálj' : (level.fontossag === 'ma' ? 'Ma' : ''),
            cim: level.felado,
            szoveg: level.targy,
            megj: level.ok,
            maradjon: azonnal,
            kattintas: function () { levelhez(level.id); }
        });

        if (window.Notification && window.Notification.permission === 'granted') {
            try {
                var n = new window.Notification((azonnal ? 'AZONNAL: ' : 'Új levél: ') + level.felado, {
                    body: (hova ? hova + ' – ' : '') + level.targy + (level.ok ? '\n' + level.ok : ''),
                    tag: 'sdh-level-' + level.id,
                    requireInteraction: azonnal
                });

                n.onclick = function () {
                    window.focus();
                    levelhez(level.id);
                    n.close();
                };
            } catch (hiba) {
                /* a felugró jelzés megvan */
            }
        }
    }

    function allapot() {
        var utolso = tarol(UTOLSO_KULCS);
        var mezok = {};

        if (utolso !== null && utolso !== '') {
            mezok.utolso = utolso;
        }

        return kuld('allapot', mezok).then(function (adat) {
            jelveny(adat.olvasatlan, adat.azonnal);
            tarol(UTOLSO_KULCS, String(adat.utolso));

            (adat.fiokok || []).forEach(function (f) {
                fiokNevek[f.kulcs] = f.nev;
            });

            (adat.ujak || []).forEach(ertesit);

            (adat.fiokok || []).forEach(function (f) {
                if (f.uj_zarva > 0) {
                    toast({
                        cim: f.nev,
                        szoveg: f.uj_zarva + ' új levél a jelszóval védett fiókban',
                        megj: 'Nyisd meg a jelszavával a Levelezésben.',
                        kattintas: function () { window.location.href = B.levelUrl || ''; }
                    });
                }
            });

            if ((adat.ujak || []).length && adat.hang) {
                hang();
            }

            document.dispatchEvent(new CustomEvent('sdh:level-allapot', { detail: adat }));

            return adat;
        }).catch(function () {
            /* a következő körben újra próbáljuk */
        });
    }

    if (B.level) {
        var kor = 60000;

        window.setTimeout(function ciklus() {
            allapot().then(function (adat) {
                if (adat && adat.frissites) {
                    kor = Math.max(30, adat.frissites) * 1000;
                }

                window.setTimeout(ciklus, kor);
            });
        }, 1200);
    }

    /* Beállítások: a kapcsolat ellenőrzése. */
    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-level-teszt]') : null;

        if (!gomb) {
            return;
        }

        var ki = gomb.parentNode.querySelector('[data-sdh-level-teszt-ki]');

        esemeny.preventDefault();
        gomb.disabled = true;

        if (ki) {
            ki.className = '';
            ki.textContent = 'Ellenőrzés…';
        }

        kuld('teszt', { fiok: gomb.getAttribute('data-sdh-level-teszt') }).then(function (adat) {
            if (ki) {
                ki.className = 'is-jo';
                ki.textContent = adat.uzenet;
            }
        }).catch(function (hiba) {
            if (ki) {
                ki.className = 'is-hiba';
                ki.textContent = hiba.message;
            }
        }).then(function () {
            gomb.disabled = false;
        });
    });

    /* A levélíró ablak: piszkozat mentése, aláírás a feladó váltásakor. */
    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;

        if (!cel.closest) {
            return;
        }

        var urlap = cel.closest('[data-sdh-leveliro]');

        if (!urlap) {
            return;
        }

        var jel = urlap.querySelector('[data-sdh-level-piszkozat-jel]');
        var cimzett = urlap.querySelector('[name="cimzett"]');

        if (cel.closest('[data-sdh-level-piszkozat]')) {
            esemeny.preventDefault();
            jel.value = '1';
            // Piszkozathoz nem kell címzett.
            cimzett.required = false;

            if (urlap.requestSubmit) {
                urlap.requestSubmit();
            } else {
                urlap.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }

            window.setTimeout(function () { cimzett.required = true; }, 0);
        } else if (cel.closest('button[type="submit"]')) {
            jel.value = '0';
        }
    }, true);

    /* ---- Levélíró: a feladó „pill"-je (választás felugró ablakban) ---- */

    function feladok(mezo) {
        try {
            return JSON.parse(mezo.getAttribute('data-fiokok') || '[]');
        } catch (hiba) {
            return [];
        }
    }

    function feladoValt(mezo, elem) {
        var urlap = mezo.form;
        var szoveg = urlap.querySelector('[name="szoveg"]');
        var regi = null;
        var uj = elem.alairas || '';

        // Új levélnél a feladó váltásával az aláírás is cserélődik (ha a szöveg még csak az aláírás).
        feladok(mezo).forEach(function (f) {
            if (f.alairas && szoveg.value.trim() === String(f.alairas).trim()) {
                regi = f.alairas;
            }
        });

        if (szoveg.value.trim() === '' || regi !== null) {
            szoveg.value = uj ? '\n\n' + uj : '';
        }

        mezo.value = elem.ertek;
        urlap.querySelector('[data-sdh-level-felado-nev]').textContent = elem.cimke;
        urlap.querySelector('[data-sdh-level-felado-cim]').textContent = elem.alcim;
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-level-felado-gomb]') : null;

        if (!gomb || gomb.disabled || !app() || !app().pillek) {
            return;
        }

        var mezo = gomb.parentNode.querySelector('[data-sdh-level-felado]');

        app().pillek(gomb, {
            cim: 'Melyik címről menjen a levél?',
            elemek: feladok(mezo),
            aktualis: mezo.value,
            valaszt: function (ertek, elem) { feladoValt(mezo, elem); }
        });
    });

    // A kiválasztott csatolmányok neve a gomb mellett (a böngésző saját fájlmezője rejtve van).
    document.addEventListener('change', function (esemeny) {
        var mezo = esemeny.target;

        if (!mezo.matches || !mezo.matches('[data-sdh-level-fajl]')) {
            return;
        }

        var nevek = Array.prototype.map.call(mezo.files || [], function (f) { return f.name; });
        var hely = mezo.form.querySelector('[data-sdh-level-fajlok]');

        hely.textContent = nevek.length ? (nevek.length > 1 ? nevek.length + ' fájl: ' : '') + nevek.join(', ') : '';
        hely.title = nevek.join('\n');
    });

    /* ---------------------------------------------------------------- */
    /* Rendező ügynök: kérés → terv → jóváhagyás → eredmény             */
    /* ---------------------------------------------------------------- */

    var RENDEZO_LAP = 3;       // ennyi csoport látszik egyszerre a tervben (a popupban nincs görgetősáv)

    function rendezoLepes(urlap, lepes) {
        urlap.setAttribute('data-lepes', lepes);

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-rendezo-lepes]'), function (d) {
            d.hidden = d.getAttribute('data-rendezo-lepes') !== lepes;
        });

        var gomb = urlap.querySelector('[data-rendezo-gomb]');
        var vissza = urlap.querySelector('[data-rendezo-vissza]');
        var megsem = urlap.querySelector('[data-sdh-megsem]');
        var terv = urlap.sdhTerv ? urlap.sdhTerv.terv : null;

        gomb.disabled = false;
        gomb.textContent = lepes === 'keres' ? 'Terv készítése' : 'Végrehajtás';
        gomb.hidden = lepes === 'eredmeny' || (lepes === 'terv' && !(terv && terv.csoportok.length));
        vissza.hidden = lepes !== 'terv';
        megsem.textContent = lepes === 'eredmeny' ? 'Bezárás' : 'Mégsem';
    }

    function rendezoLapoz(urlap, lap) {
        var csoportok = urlap.querySelectorAll('[data-rendezo-csoportdoboz]');
        var lapok = Math.max(1, Math.ceil(csoportok.length / RENDEZO_LAP));

        lap = Math.max(0, Math.min(lapok - 1, lap));
        urlap.sdhLap = lap;

        Array.prototype.forEach.call(csoportok, function (d, i) {
            d.hidden = Math.floor(i / RENDEZO_LAP) !== lap;
        });

        var jelzo = urlap.querySelector('[data-rendezo-lapjelzo]');

        if (jelzo) {
            jelzo.textContent = (lap + 1) + ' / ' + lapok;
            urlap.querySelector('[data-rendezo-lap="-1"]').disabled = lap <= 0;
            urlap.querySelector('[data-rendezo-lap="1"]').disabled = lap >= lapok - 1;
        }
    }

    function rendezoSorok(sorok, osztaly) {
        return (sorok || []).length
            ? '<ul class="sdh-rendezo__lista ' + osztaly + '">' + sorok.map(function (sor) { return '<li>' + e(sor) + '</li>'; }).join('') + '</ul>'
            : '';
    }

    /** A terv kirajzolása: mit tenne az ügynök. Az új mappa létrehozása külön, alapból be nem pipált engedély. */
    function rendezoTerv(urlap, adat) {
        var t = adat.terv;
        var doboz = urlap.querySelector('[data-rendezo-lepes="terv"]');
        var html = (t.uzenet ? '<p class="sdh-rendezo__uzenet" data-rendezo-uzenet>' + e(t.uzenet) + '</p>' : '') +
            rendezoSorok(adat.eredmeny, 'is-kesz') + rendezoSorok(t.kihagyva, 'is-kihagyva');

        if (t.uj_mappak.length) {
            html += '<fieldset class="sdh-rendezo__engedely"><legend>Engedélyt kér: új mappa létrehozása</legend>' +
                t.uj_mappak.map(function (nev) {
                    return '<label class="sdh-jelolo"><input type="checkbox" data-rendezo-uj value="' + e(nev) + '"> Engedélyezem a(z) „' + e(nev) + '" mappa létrehozását</label>';
                }).join('') + '</fieldset>';
        }

        if (!t.csoportok.length) {
            html += '<p class="sdh-rendezo__megj" data-rendezo-ures>Nincs áthelyezendő levél.</p>';
        }

        t.csoportok.forEach(function (cs) {
            html += '<div class="sdh-rendezo__csoport" data-rendezo-csoportdoboz>' +
                '<label class="sdh-jelolo"><input type="checkbox" data-rendezo-csoport value="' + cs.i + '" checked> ' +
                '<strong>' + cs.db + ' levél</strong> → ' + e(cs.cel) + (cs.uj ? ' <span class="sdh-level-jel sdh-level-jel--ma">új mappa</span>' : '') + '</label>' +
                '<ul class="sdh-rendezo__minta">' + cs.minta.map(function (m) {
                    return '<li><span>' + e(m.felado) + '</span> ' + e(m.targy) + '</li>';
                }).join('') + (cs.db > cs.minta.length ? '<li class="sdh-rendezo__meg">… és még ' + (cs.db - cs.minta.length) + ' levél</li>' : '') + '</ul></div>';
        });

        if (t.csoportok.length > RENDEZO_LAP) {
            html += '<div class="sdh-rendezo__lapozo"><button type="button" class="sdh-gomb sdh-gomb--vilagos" data-rendezo-lap="-1" aria-label="Előző csoportok">' + NYIL_BAL + '</button>' +
                '<span data-rendezo-lapjelzo></span>' +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-rendezo-lap="1" aria-label="További csoportok">' + NYIL_JOBB + '</button>' +
                '<span class="sdh-rendezo__megj">' + t.csoportok.length + ' csoport, összesen ' + t.db + ' levél</span></div>';
        }

        doboz.innerHTML = html;
        urlap.sdhTerv = adat;
        rendezoLepes(urlap, 'terv');
        rendezoLapoz(urlap, 0);
    }

    function rendezoEredmeny(urlap, sorok, fiokok) {
        urlap.querySelector('[data-rendezo-lepes="eredmeny"]').innerHTML = '<p class="sdh-rendezo__uzenet">Kész:</p>' + rendezoSorok(sorok, 'is-kesz');
        rendezoLepes(urlap, 'eredmeny');
        document.dispatchEvent(new CustomEvent('sdh:level-rendezve', { detail: { fiokok: fiokok } }));
    }

    // Az app.js általános űrlapküldője helyett: az ablak több lépésből áll, és nem zárul be a terv után.
    document.addEventListener('submit', function (esemeny) {
        var urlap = esemeny.target;

        if (!urlap.matches || !urlap.matches('[data-sdh-rendezo]')) {
            return;
        }

        esemeny.preventDefault();
        esemeny.stopPropagation();

        var gomb = urlap.querySelector('[data-rendezo-gomb]');
        var hiba = function (h) {
            gomb.disabled = false;
            rendezoLepes(urlap, urlap.getAttribute('data-lepes'));

            if (app().hiba) {
                app().hiba(urlap, h.message);
            }
        };
        var regiHiba = urlap.querySelector('.sdh-modal__hiba');

        if (regiHiba) {
            regiHiba.remove();
        }

        gomb.disabled = true;

        if (urlap.getAttribute('data-lepes') === 'keres') {
            var mezok = {};

            Array.prototype.forEach.call(urlap.querySelectorAll('input[name], textarea[name]'), function (m) {
                if (m.name !== '_wpnonce') {
                    mezok[m.name] = m.value;
                }
            });

            gomb.textContent = 'Terv készül…';

            kuld('rendezo_terv', mezok).then(function (adat) {
                // Jóváhagyás nélküli módban, ha nem kell új mappa, a szerver már végre is hajtotta.
                if (adat.eredmeny && !adat.terv.uj_mappak.length) {
                    rendezoEredmeny(urlap, adat.eredmeny, adat.fiokok);

                    return;
                }

                if (adat.eredmeny) {
                    // Ami meglévő mappába ment, az kész; a tervben már csak az új mappára várók maradnak.
                    adat.terv.csoportok = adat.terv.csoportok.filter(function (cs) { return cs.uj; });
                    document.dispatchEvent(new CustomEvent('sdh:level-rendezve', { detail: { fiokok: adat.fiokok } }));
                }

                rendezoTerv(urlap, adat);
            }).catch(hiba);

            return;
        }

        var uj = Array.prototype.map.call(urlap.querySelectorAll('[data-rendezo-uj]:checked'), function (p) { return p.value; });
        var csoport = Array.prototype.map.call(urlap.querySelectorAll('[data-rendezo-csoport]:checked'), function (p) { return p.value; });

        if (!csoport.length) {
            hiba(new Error('Jelölj be legalább egy áthelyezést, vagy zárd be az ablakot.'));

            return;
        }

        gomb.textContent = 'Végrehajtás…';

        kuld('rendezo_vegrehajt', { token: urlap.sdhTerv.token, uj: uj, csoport: csoport }).then(function (adat) {
            rendezoEredmeny(urlap, adat.eredmeny, adat.fiokok);
        }).catch(hiba);
    }, true);

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;
        var urlap = cel.closest ? cel.closest('[data-sdh-rendezo]') : null;

        if (!urlap) {
            return;
        }

        if (cel.closest('[data-rendezo-vissza]')) {
            rendezoLepes(urlap, 'keres');
        } else if (cel.closest('[data-rendezo-lap]')) {
            rendezoLapoz(urlap, (urlap.sdhLap || 0) + parseInt(cel.closest('[data-rendezo-lap]').getAttribute('data-rendezo-lap'), 10));
        }
    });

    /* ================================================================ */
    /* 2. A levelező oldal                                              */
    /* ================================================================ */

    var gyoker = document.querySelector('[data-sdh-level]');

    if (!gyoker) {
        return;
    }

    var A = {
        fiokok: [],
        fiok: '',
        mappa: '',          // mappa-azonosító, vagy 'fontos' (az ügynök jelzései)
        oldal: 1,
        oldalak: 1,
        ossz: 0,
        q: '',
        sorok: [],
        kijelolt: {},
        level: null,        // a megnyitott levél
        tolt: false,
        hiba: '',
        ujMappa: '',        // melyik fiókban van nyitva az „Új mappa" mező
        atnevez: false,
        megerosit: ''       // a kétlépéses törlés éppen megerősítésre váró művelete
    };

    try {
        A.fiokok = JSON.parse(gyoker.getAttribute('data-fiokok') || '[]');
    } catch (hiba) {
        A.fiokok = [];
    }

    gyoker.innerHTML =
        '<div class="sdh-level__fej">' +
        '  <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-l="uj">Új levél</button>' +
        '  <form class="sdh-level__kereso" data-l-kereso>' +
        '    <input type="search" autocomplete="off" placeholder="Keresés a megnyitott mappában…" aria-label="Keresés a levelek között">' +
        '    <button type="submit" class="sdh-gomb sdh-gomb--vilagos">Keresés</button>' +
        '  </form>' +
        '  <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l="frissit" title="Új levelek és mappák lekérése">Frissítés</button>' +
        (B.levelRendezo ? '  <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l="rendezo" title="Leveleket rendez mappákba a kérésedre – csak azt, amit jóváhagysz">Rendező ügynök</button>' : '') +
        '  <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l="ertesites" hidden title="A böngésző akkor is szól, ha másik ablakban dolgozol">Értesítések bekapcsolása</button>' +
        '</div>' +
        '<div class="sdh-level__uzenet" data-l-uzenet hidden></div>' +
        '<div class="sdh-level__fulek" data-l-fulek role="tablist" aria-label="E-mail-fiókok"></div>' +
        '<div class="sdh-level__torzs">' +
        '  <nav class="sdh-level__mappak" data-l-mappak aria-label="A fiók mappái"></nav>' +
        '  <section class="sdh-level__lista" data-l-lista aria-label="Levelek"></section>' +
        '  <section class="sdh-level__olvaso" data-l-olvaso aria-label="A megnyitott levél"></section>' +
        '</div>';

    var elFulek = gyoker.querySelector('[data-l-fulek]');
    var elMappak = gyoker.querySelector('[data-l-mappak]');
    var elLista = gyoker.querySelector('[data-l-lista]');
    var elOlvaso = gyoker.querySelector('[data-l-olvaso]');
    var elUzenet = gyoker.querySelector('[data-l-uzenet]');
    var elErtesites = gyoker.querySelector('[data-l="ertesites"]');

    function magassag() {
        var torzs = gyoker.querySelector('.sdh-level__torzs');

        torzs.style.height = Math.max(360, window.innerHeight - torzs.getBoundingClientRect().top - 18) + 'px';
    }

    window.addEventListener('resize', magassag);

    function uzen(szoveg, tipus) {
        elUzenet.hidden = !szoveg;
        elUzenet.className = 'sdh-level__uzenet' + (tipus ? ' is-' + tipus : '');
        elUzenet.textContent = szoveg || '';
        magassag();
    }

    function fiok(kulcs) {
        return A.fiokok.filter(function (f) { return f.kulcs === (kulcs || A.fiok); })[0] || null;
    }

    function mappa(id, fiokKulcs) {
        var f = fiok(fiokKulcs);

        return f ? (f.mappak || []).filter(function (m) { return String(m.id) === String(id === undefined ? A.mappa : id); })[0] || null : null;
    }

    function szerepMappa(szerep, fiokKulcs) {
        var f = fiok(fiokKulcs);

        return f ? (f.mappak || []).filter(function (m) { return m.szerep === szerep; })[0] || null : null;
    }

    /* ---------------------------------------------------------------- */
    /* Mappák                                                           */
    /* ---------------------------------------------------------------- */

    /**
     * `hatterbol`: háttérfrissítés (új levél, lista újratöltése) kérte. Ilyenkor nem
     * rajzolunk újra, ha valaki éppen ír a hasábban (jelszó, új mappa neve) – ne vesszen el, amit gépel.
     */
    var LAKAT = '<svg class="sdh-level__lakat" viewBox="0 0 12 12" aria-hidden="true"><rect x="2.5" y="5.4" width="7" height="5" rx="1.1"/><path d="M4.1 5.4V4a1.9 1.9 0 0 1 3.8 0v1.4"/></svg>';

    /**
     * A fiókok lapfülei. Minden e-mail-cím külön lapon van: egyszerre egy fiók mappái és levelei
     * látszanak, a két postafiók semmiben nem keveredik.
     */
    function rajzolFulek() {
        elFulek.innerHTML = A.fiokok.map(function (f) {
            var aktiv = f.kulcs === A.fiok;

            return '<button type="button" role="tab" class="sdh-level__ful' + (aktiv ? ' is-aktiv' : '') + (f.zarva ? ' is-zarva' : '') + '"' +
                ' data-l-ful="' + e(f.kulcs) + '" aria-selected="' + (aktiv ? 'true' : 'false') + '" title="' + e(f.email) + (f.zarva ? ' – jelszóval védett' : '') + '">' +
                '<span class="sdh-level__fulnev">' + e(f.nev) + '</span>' +
                '<span class="sdh-level__fulcim">' + e(f.email) + '</span>' +
                (f.zarva ? LAKAT : '') +
                (!f.zarva && f.azonnal > 0 ? '<span class="sdh-level__fuljel" title="Azonnali teendő">' + f.azonnal + '</span>' : '') +
                (!f.zarva && f.olvasatlan > 0 ? '<span class="sdh-level__fuldb" title="Olvasatlan a Beérkezettben">' + f.olvasatlan + '</span>' : '') +
                '</button>';
        }).join('');
    }

    function rajzolMappak(hatterbol) {
        var aktiv = document.activeElement;

        rajzolFulek();

        if (hatterbol && aktiv && aktiv.tagName === 'INPUT' && elMappak.contains(aktiv)) {
            return;
        }

        var f = fiok();
        var html = '';

        if (!f) {
            elMappak.innerHTML = '';

            return;
        }

        html += '<div class="sdh-level__fiok" data-l-fiok="' + e(f.kulcs) + '">' +
            '<div class="sdh-level__fiokfej"><span class="sdh-level__email" title="' + e(f.email) + '">' + e(f.email) + '</span>' +
            (f.vedett && !f.zarva ? '<button type="button" class="sdh-level__kis" data-l="zar" data-fiok="' + e(f.kulcs) + '" title="A fiók újra jelszót kér">Zárás</button>' : '') +
            '</div>';

        if (f.zarva) {
            html += '<form class="sdh-level__zar" data-l-nyit="' + e(f.kulcs) + '">' +
                '<p>Ez a postafiók jelszóval védett.</p>' +
                '<input type="password" autocomplete="off" placeholder="Jelszó" aria-label="' + e(f.nev) + ' jelszava" required>' +
                '<button type="submit" class="sdh-gomb sdh-gomb--vilagos">Megnyitás</button>' +
                '<span class="sdh-level__zarhiba" aria-live="polite"></span></form></div>';

            elMappak.innerHTML = html;

            return;
        }

        // A fiók saját teendői (az ügynök jelzései) – csak ennek a fióknak a leveleiből.
        html += '<button type="button" class="sdh-level__mappa sdh-level__mappa--teendo' + (A.mappa === 'fontos' ? ' is-aktiv' : '') + '" data-l-mappa="fontos" data-fiok="' + e(f.kulcs) + '">' +
            '<span class="sdh-level__mappanev">Teendők</span>' +
            '<span class="sdh-level__db' + (f.azonnal > 0 ? ' is-azonnal' : '') + '" title="Azonnali teendő"' + (f.azonnal > 0 ? '' : ' hidden') + '>' + (f.azonnal || 0) + '</span></button>';

        if (f.hiba) {
            html += '<p class="sdh-level__fiokhiba" title="' + e(f.hiba) + '">Kapcsolati hiba – a legutóbbi állapot látszik.</p>';
        }

        var cimkek = false;

        (f.mappak || []).forEach(function (m) {
            // A „[Gmail]" csak tároló: nem mappa, nem mutatjuk.
            if (!m.valaszthato && m.szerep === '' && /^\[(Gmail|Google Mail)\]$/.test(m.teljes)) {
                return;
            }

            if (m.szerep === '' && !cimkek) {
                cimkek = true;
                html += '<p class="sdh-level__cimkefej">Címkék</p>';
            }

            var kivalasztva = String(A.mappa) === String(m.id);

            html += m.valaszthato
                ? '<button type="button" class="sdh-level__mappa' + (kivalasztva ? ' is-aktiv' : '') + (m.olvasatlan > 0 ? ' is-olvasatlan' : '') + '"' +
                    ' data-l-mappa="' + m.id + '" data-fiok="' + e(f.kulcs) + '" data-szerep="' + e(m.szerep) + '" title="' + e(m.teljes) + ' – ' + m.osszes + ' levél"' +
                    ' style="padding-left:' + (0.7 + m.szint * 0.9) + 'rem">' +
                    '<span class="sdh-level__mappanev">' + e(m.nev) + '</span>' +
                    (m.olvasatlan > 0 ? '<span class="sdh-level__db">' + m.olvasatlan + '</span>' : '') + '</button>'
                : '<span class="sdh-level__mappa sdh-level__mappa--tarolo" style="padding-left:' + (0.7 + m.szint * 0.9) + 'rem">' + e(m.nev) + '</span>';
        });

        html += A.ujMappa === f.kulcs
            ? '<form class="sdh-level__ujmappa" data-l-ujmappa="' + e(f.kulcs) + '"><input type="text" maxlength="80" placeholder="Az új mappa neve" aria-label="Az új mappa neve" required>' +
                '<button type="submit" class="sdh-gomb sdh-gomb--vilagos">OK</button></form>'
            : '<button type="button" class="sdh-level__kis sdh-level__kis--uj" data-l="ujmappa" data-fiok="' + e(f.kulcs) + '"><span class="sdh-plusz sdh-plusz--kicsi" aria-hidden="true"></span>Új mappa</button>';

        html += '</div>';

        elMappak.innerHTML = html;

        var mezo = elMappak.querySelector('[data-l-ujmappa] input');

        if (mezo) {
            mezo.focus();
        }
    }

    /* ---------------------------------------------------------------- */
    /* Lista                                                            */
    /* ---------------------------------------------------------------- */

    function kijeloltIdk() {
        return Object.keys(A.kijelolt).filter(function (id) { return A.kijelolt[id]; });
    }

    function jelHtml(sor) {
        if (sor.fontossag !== 'azonnal' && sor.fontossag !== 'ma') {
            return '';
        }

        return '<span class="sdh-level-jel sdh-level-jel--' + sor.fontossag + (sor.elintezve ? ' is-kesz' : '') + '" title="' + e(sor.ok || '') + (sor.elintezve ? ' (elintézve)' : '') + '">' +
            SZINT[sor.fontossag] + '</span>';
    }

    function rajzolLista() {
        if ((fiok() || {}).zarva) {
            elLista.innerHTML = '<p class="sdh-level__ures">Ez a postafiók jelszóval védett. A bal oldalon add meg a jelszavát, és megnyílik.</p>';

            return;
        }

        var regiSorok = elLista.querySelector('[data-l-sorok]');
        var gorgetes = regiSorok ? regiSorok.scrollTop : 0;
        var m = mappa();
        var szerep = A.mappa === 'fontos' ? 'fontos' : (m ? m.szerep : '');
        var kuldott = szerep === 'sent' || szerep === 'drafts';
        var idk = kijeloltIdk();
        var van = idk.length > 0;
        var cim = A.mappa === 'fontos' ? 'Teendők – amire reagálni kell' : (m ? (m.szerep ? m.nev : m.teljes) : '');
        var vegleg = szerep === 'trash' || szerep === 'junk' || szerep === 'drafts';
        var tiltva = van ? '' : ' disabled';
        var html = '<div class="sdh-level__listafej"><h2>' + e(cim) + (A.q ? ' <small>– keresés: „' + e(A.q) + '" <button type="button" class="sdh-level__kis" data-l="keresestorol">törlés</button></small>' : '') + '</h2>';

        if (m && m.szerep === '' && A.mappa !== 'fontos') {
            html += A.atnevez
                ? '<form class="sdh-level__ujmappa" data-l-atnevez><input type="text" maxlength="80" value="' + e(m.nev) + '" aria-label="A mappa új neve" required>' +
                    '<button type="submit" class="sdh-gomb sdh-gomb--vilagos">OK</button></form>'
                : '<span class="sdh-level__mappamuv"><button type="button" class="sdh-level__kis" data-l="atnevez">Átnevezés</button>' +
                    '<button type="button" class="sdh-level__kis' + (A.megerosit === 'mappatorol' ? ' is-megerosit' : '') + '" data-l="mappatorol">' +
                    (A.megerosit === 'mappatorol' ? 'Biztosan törlöd a mappát?' : 'Mappa törlése') + '</button></span>';
        }

        html += '</div>';

        if (A.mappa !== 'fontos') {
            html += '<div class="sdh-level__eszkozok">' +
                '<label class="sdh-level__mind" title="Az oldal összes levele"><input type="checkbox" data-l-mind' + (A.sorok.length && idk.length === A.sorok.length ? ' checked' : '') + '></label>' +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-muv="olvasott"' + tiltva + '>Olvasott</button>' +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-muv="olvasatlan"' + tiltva + '>Olvasatlan</button>' +
                (szerep === 'inbox' && szerepMappa('all') ? '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-muv="archiv"' + tiltva + ' title="Kikerül a Beérkezettből, az Összes levélben megmarad">Archiválás</button>' : '') +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-muv="athelyez"' + tiltva + '>Áthelyezés…</button>' +
                (szerep === 'junk'
                    ? '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-muv="nem_spam"' + tiltva + '>Nem spam</button>'
                    : (szerepMappa('junk') && !kuldott && szerep !== 'trash' ? '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-muv="spam"' + tiltva + '>Spam</button>' : '')) +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-level__torles' + (A.megerosit === 'vegleg' ? ' is-megerosit' : '') + '" data-l-muv="' + (vegleg ? 'vegleg' : 'kuka') + '"' + tiltva + '>' +
                (vegleg ? (A.megerosit === 'vegleg' ? 'Biztosan? Nem vonható vissza' : 'Végleges törlés') : 'Törlés') + '</button>' +
                (van ? '<span class="sdh-level__kijdb">' + idk.length + ' kijelölve</span>' : '') +
                '</div>';
        }

        html += '<div class="sdh-level__sorok" data-l-sorok>';

        if (A.hiba) {
            html += '<p class="sdh-level__listahiba">' + e(A.hiba) + '</p>';
        }

        if (!A.sorok.length) {
            html += '<p class="sdh-level__ures">' + (A.tolt && A.toltSzoveg !== 'Mentés…' ? 'Betöltés…' : (A.q ? 'Nincs találat.' : (A.mappa === 'fontos' ? 'Nincs teendő: az ügynök nem talált levelet, amire reagálni kell.' : 'Ez a mappa üres.'))) + '</p>';
        }

        A.sorok.forEach(function (s) {
            var nyitva = A.level && A.level.id === s.id;
            var ki = kuldott ? 'Címzett: ' + (s.cimzett || '—') : s.felado;

            html += '<div class="sdh-level__sor' + (s.olvasott ? '' : ' is-olvasatlan') + (nyitva ? ' is-nyitva' : '') + (A.kijelolt[s.id] ? ' is-kijelolt' : '') +
                (s.fontossag === 'azonnal' && !s.elintezve ? ' is-azonnal' : '') + '" data-l-sor="' + s.id + '" tabindex="0" role="button" aria-label="' + e(ki + ': ' + s.targy) + '">' +
                (A.mappa === 'fontos' ? '' : '<input type="checkbox" data-l-pipa="' + s.id + '"' + (A.kijelolt[s.id] ? ' checked' : '') + ' aria-label="Kijelölés">') +
                '<button type="button" class="sdh-level__csillag' + (s.csillag ? ' is-be' : '') + '" data-l-csillag="' + s.id + '" aria-label="' + (s.csillag ? 'Csillag levétele' : 'Csillagozás') + '" title="Csillag">' +
                (s.csillag ? '★' : '☆') + '</button>' +
                '<span class="sdh-level__ki" title="' + e(kuldott ? s.cimzett : s.email) + '">' + e(ki) + '</span>' +
                '<span class="sdh-level__datum" title="' + e(s.idopont) + '">' + e(s.datum) + '</span>' +
                '<span class="sdh-level__targy">' + jelHtml(s) + (s.valaszolt ? '<span class="sdh-level__valasz" title="Megválaszolva">↩</span>' : '') + e(s.targy) + '</span>' +
                '<span class="sdh-level__ikonok">' + (s.csatolmany ? '<span title="Csatolmány" aria-label="Csatolmány">📎</span>' : '') + '</span>' +
                '<span class="sdh-level__kivonat">' + e(s.ok && A.mappa === 'fontos' ? s.ok + ' — ' : '') + e(s.kivonat) + '</span>' +
                '</div>';
        });

        html += '</div>';

        var tol = A.ossz ? (A.oldal - 1) * 25 + 1 : 0;
        var ig = Math.min(A.ossz, (A.oldal - 1) * 25 + A.sorok.length);

        html += '<div class="sdh-level__lapozo">' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-lap="-1" aria-label="Újabb levelek"' + (A.oldal <= 1 ? ' disabled' : '') + '>' + NYIL_BAL + '</button>' +
            '<span data-l-oldal>' + tol + '–' + ig + ' / ' + A.ossz + '</span>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-lap="1" aria-label="Régebbi levelek"' + (A.oldal >= A.oldalak ? ' disabled' : '') + '>' + NYIL_JOBB + '</button>' +
            (A.tolt ? '<span class="sdh-level__tolt">' + e(A.toltSzoveg || 'Betöltés…') + '</span>' : '') + '</div>';

        elLista.innerHTML = html;

        // A háttérfrissítés nem ugrasztja a lista elejére azt, aki éppen lejjebb görgetett.
        if (gorgetes > 0 && A.sorok.length) {
            elLista.querySelector('[data-l-sorok]').scrollTop = gorgetes;
        }

        var atnevez = elLista.querySelector('[data-l-atnevez] input');

        if (atnevez) {
            atnevez.focus();
            atnevez.select();
        }
    }

    var listaTar = {};          // fiók|mappa|oldal → a legutóbbi válasz (azonnali rajzhoz mappaváltáskor)
    var levelTar = {};          // levél-azonosító → a megnyitott levél (újranyitás várakozás nélkül)

    /** A szerver válaszának átvétele és kirajzolása. */
    function listaAlkalmaz(adat) {
        A.sorok = adat.sorok || [];
        A.ossz = adat.ossz || 0;
        A.oldal = adat.oldal || 1;
        A.oldalak = adat.oldalak || 1;
        A.fiokok = adat.fiokok || A.fiokok;
        A.hiba = adat.hiba || '';

        if (adat.mappa && A.mappa !== 'fontos') {
            A.mappa = String(adat.mappa);
        }

        // Ami már nincs a listában, az kijelölve sem maradhat.
        var megvan = {};

        A.sorok.forEach(function (s) {
            if (A.kijelolt[s.id]) {
                megvan[s.id] = true;
            }
        });

        A.kijelolt = megvan;
        rajzolMappak(true);
        rajzolLista();
    }

    /**
     * A lista betöltése két lépésben, hogy azonnal látsszon:
     *   1. a szerver gyorsítótárából (a levelezőszerver megkérdezése nélkül) – ez tizedmásodpercek;
     *   2. a háttérben frissítés a levelezőszerverről (új levelek, máshol történt változások).
     * `csendben`: a lista nem ürül ki közben. `csakGyors`: a 2. lépés kimarad (művelet után, amikor a
     * szerver éppen most igazította a gyorsítótárat).
     */
    function betolt(csendben, csakGyors) {
        var keres = (A.keres = (A.keres || 0) + 1);
        var kulcs = A.fiok + '|' + A.mappa + '|' + A.oldal;
        var gyorsan = A.mappa !== 'fontos' && A.mappa !== '' && !A.q;
        var kertFiok = A.fiok;
        var mezok = { fiok: A.fiok, mappa: A.mappa, oldal: A.oldal, q: A.q };

        A.tolt = true;
        A.toltSzoveg = csendben ? 'Frissítés…' : 'Betöltés…';

        if (!csendben && gyorsan && listaTar[kulcs]) {
            // Ebben a munkamenetben már láttuk: azonnal kirajzoljuk, a frissítés a háttérben megy.
            A.toltSzoveg = 'Frissítés…';
            listaAlkalmaz(listaTar[kulcs]);
        } else {
            rajzolLista();
        }

        function hibakezelo(hiba) {
            if (keres !== A.keres) {
                return;
            }

            A.tolt = false;

            if (hiba.adat && hiba.adat.zarva) {
                return frissitFiokok();
            }

            // Ha a gyorsítótárból már van mit mutatni, az marad; csak a hibát írjuk fölé.
            A.hiba = hiba.message;
            rajzolLista();
        }

        function teljes() {
            return kuld('lista', mezok).then(function (adat) {
                if (keres !== A.keres) {
                    return;
                }

                A.tolt = false;
                listaTar[kertFiok + '|' + (adat.mappa || A.mappa) + '|' + (adat.oldal || A.oldal)] = adat;
                listaAlkalmaz(adat);
            });
        }

        if (!gyorsan) {
            return teljes().catch(hibakezelo);
        }

        var gyorsMezok = { gyors: 1 };

        Object.keys(mezok).forEach(function (k) { gyorsMezok[k] = mezok[k]; });

        return kuld('lista', gyorsMezok).then(function (adat) {
            if (keres !== A.keres) {
                return;
            }

            if (!adat.gyors) {
                // Ehhez a mappához még nem volt gyorsítótár: a szerver rögtön a teljes választ adta.
                A.tolt = false;
                listaTar[kulcs] = adat;
                listaAlkalmaz(adat);

                return;
            }

            A.toltSzoveg = 'Frissítés…';

            if (csakGyors && !adat.hianyos) {
                A.tolt = false;
                listaTar[kulcs] = adat;
                listaAlkalmaz(adat);

                return;
            }

            // Ha az oldalhoz még nincs meg minden levél a gyorsítótárban, a meglévőket mutatjuk, a többi mindjárt jön.
            if (!adat.hianyos || (adat.sorok || []).length) {
                listaAlkalmaz(adat);
            }

            return teljes();
        }).catch(hibakezelo);
    }

    function frissitFiokok() {
        // A zárolt fiók adatai a szerveren maradnak: a mappák a feloldás után jönnek.
        return kuld('allapot', {}).then(function () {
            window.location.reload();
        });
    }

    function valt(fiokKulcs, mappaId) {
        A.fiok = fiokKulcs;
        A.mappa = String(mappaId);
        A.oldal = 1;
        A.q = '';
        A.kijelolt = {};
        A.atnevez = false;
        A.megerosit = '';
        A.sorok = [];
        gyoker.querySelector('[data-l-kereso] input').value = '';
        rajzolMappak();

        return betolt();
    }

    /** Lapfül: másik fiók. A megnyitott levél bezárul, a fiók Beérkezett mappája (vagy a jelszókérő) jön. */
    function fulre(kulcs) {
        var f = fiok(kulcs);

        if (!f) {
            return;
        }

        tarol('sdh-level-fiok', kulcs);
        A.level = null;
        A.ujMappa = '';
        rajzolOlvaso();

        if (f.zarva) {
            // A folyamatban lévő listakérés válasza már nem ide tartozik.
            A.keres = (A.keres || 0) + 1;
            A.fiok = kulcs;
            A.mappa = '';
            A.sorok = [];
            A.ossz = 0;
            A.oldal = 1;
            A.oldalak = 1;
            A.q = '';
            A.kijelolt = {};
            A.tolt = false;
            A.hiba = '';
            rajzolMappak();
            rajzolLista();

            var jelszo = elMappak.querySelector('[data-l-nyit] input');

            if (jelszo) {
                jelszo.focus();
            }

            return;
        }

        valt(kulcs, (szerepMappa('inbox', kulcs) || {}).id || '');
    }

    /* ---------------------------------------------------------------- */
    /* Olvasó                                                           */
    /* ---------------------------------------------------------------- */

    function rajzolOlvaso() {
        var l = A.level;

        if (!l) {
            elOlvaso.innerHTML = '<p class="sdh-level__ures sdh-level__ures--olvaso">Válassz egy levelet a listából.</p>';

            return;
        }

        if (l.tolt) {
            // A tárgy és a feladó a listából már megvan: azonnal látszik, a törzs érkezéséig is.
            elOlvaso.innerHTML = (l.elozetes
                ? '<div class="sdh-level__olvasofej"><h2>' + e(l.elozetes.targy) + '</h2></div>' +
                    '<dl class="sdh-level__adatok"><dt>Feladó</dt><dd>' + e(l.elozetes.felado) + '</dd><dt>Dátum</dt><dd>' + e(l.elozetes.idopont || '') + '</dd></dl>'
                : '') + '<p class="sdh-level__ures sdh-level__ures--olvaso" data-l-leveltolt>A levél betöltése…</p>';

            return;
        }

        var piszkozat = l.szerep === 'drafts';
        var vegleg = l.szerep === 'trash' || l.szerep === 'junk' || piszkozat;
        var html = '<div class="sdh-level__olvasofej">' +
            '<h2 data-l-targy>' + e(l.targy) + '</h2>' +
            '<div class="sdh-level__gombok">' +
            (piszkozat
                ? '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-l-ir="piszkozat">Folytatás</button>'
                : '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-l-ir="valasz">Válasz</button>' +
                    '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-ir="mindenkinek">Válasz mindenkinek</button>' +
                    '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-ir="tovabbit">Továbbítás</button>') +
            (l.szerep === 'inbox' && szerepMappa('all', l.fiok) ? '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-egy="archiv">Archiválás</button>' : '') +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-egy="athelyez">Áthelyezés…</button>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-egy="olvasatlan">Olvasatlan</button>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l-egy="' + (l.csillag ? 'csillag_le' : 'csillag') + '">' + (l.csillag ? '★ Csillag le' : '☆ Csillag') + '</button>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-level__torles' + (A.megerosit === 'egyvegleg' ? ' is-megerosit' : '') + '" data-l-egy="' + (vegleg ? 'vegleg' : 'kuka') + '">' +
            (vegleg ? (A.megerosit === 'egyvegleg' ? 'Biztosan? Nem vonható vissza' : 'Végleges törlés') : 'Törlés') + '</button>' +
            '</div></div>';

        if (l.fontossag === 'azonnal' || l.fontossag === 'ma') {
            html += '<div class="sdh-level__ugynok sdh-level__ugynok--' + l.fontossag + (l.elintezve ? ' is-kesz' : '') + '" data-l-ugynok>' +
                '<span class="sdh-level-jel sdh-level-jel--' + l.fontossag + '">' + SZINT[l.fontossag] + '</span>' +
                '<span class="sdh-level__ugynokok">' + e(l.fontossag === 'azonnal' ? 'Erre 1 órán belül reagálni kell' : 'Erre még ma válaszolni kell') + (l.ok ? ' – ' + e(l.ok) : '') +
                ' <small>(' + (l.ugynok === 'ai' ? 'AI' : 'szabály') + ' szerint; az ügynök csak jelez)</small></span>' +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-l="elintezve">' + (l.elintezve ? 'Mégsem elintézett' : 'Elintézve') + '</button></div>';
        }

        html += '<dl class="sdh-level__adatok">' +
            '<dt>Feladó</dt><dd data-l-felado>' + e(l.felado_teljes || l.felado) +
            (l.ugyfel ? ' <a class="sdh-level__ugyfel" href="' + e((gyoker.getAttribute('data-ugyfel-url') || '') + (/\?/.test(gyoker.getAttribute('data-ugyfel-url') || '') ? '&' : '?') + 'nezet=szerkeszt&id=' + l.ugyfel.id) + '" title="Ügyfél a CRM-ben">Ügyfél: ' + e(l.ugyfel.nev) + '</a>' : '') + '</dd>' +
            '<dt>Címzett</dt><dd>' + e(l.cimzett_teljes || '') + '</dd>' +
            (l.masolat ? '<dt>Másolat</dt><dd>' + e(l.masolat) + '</dd>' : '') +
            '<dt>Dátum</dt><dd>' + e(l.datum_teljes) + '</dd></dl>';

        if (l.tavoli_kep) {
            html += '<div class="sdh-level__kepek">A levél távoli képei nincsenek betöltve (a feladó így nem látja, hogy megnyitottad). ' +
                '<button type="button" class="sdh-level__kis" data-l="kepek">Képek megjelenítése</button></div>';
        }

        if (l.csatolmanyok && l.csatolmanyok.length) {
            html += '<div class="sdh-level__csatolmanyok" data-l-csat>' + l.csatolmanyok.map(function (c) {
                return '<a class="sdh-level__csat" href="' + e(c.url) + '" download title="Letöltés">📎 ' + e(c.nev) + ' <small>' + e(c.meret) + '</small></a>';
            }).join('') + '</div>';
        }

        html += '<iframe class="sdh-level__keret" data-l-keret title="A levél tartalma" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>';

        elOlvaso.innerHTML = html;
        // A levél HTML-je elzárt keretbe kerül: ott szkript nem fut, a CRM-hez nem fér hozzá.
        elOlvaso.querySelector('[data-l-keret]').srcdoc = l.keret || '';
    }

    function olvas(id, kepek) {
        A.megerosit = '';

        // Ebben a munkamenetben már megnyitott levél: várakozás nélkül, a szerver megkérdezése nélkül.
        if (!kepek && levelTar[id]) {
            A.level = levelTar[id];
            rajzolLista();
            rajzolOlvaso();

            return Promise.resolve();
        }

        A.level = { id: id, tolt: true, elozetes: A.sorok.filter(function (s) { return s.id === id; })[0] || null };
        rajzolOlvaso();
        rajzolLista();

        var mezok = { id: id };

        if (kepek) {
            mezok.kepek = 1;
        }

        return kuld('olvas', mezok).then(function (adat) {
            // A gyorsítótárból jött levelet a szerveren külön, háttérben jelöljük olvasottnak – a megjelenítés nem vár rá.
            if (adat.jelolendo) {
                kuld('olvasva', { id: id }).catch(function () { /* a következő szinkron úgyis egyeztet */ });
            }

            if (!kepek) {
                levelTar[id] = adat;
            }

            if (!A.level || A.level.id !== id) {
                return;
            }

            A.level = adat;
            A.fiokok = adat.fiokok || A.fiokok;

            A.sorok.forEach(function (s) {
                if (s.id === id) {
                    s.olvasott = true;
                    s.csatolmany = adat.csatolmany;
                }
            });

            // Értesítésből érkezve (akár a másik fiók leveléhez): a levél saját fiókjának lapja és mappája nyílik meg mellé.
            if (!A.mappa || adat.fiok !== A.fiok) {
                A.fiok = adat.fiok;
                A.mappa = String(adat.mappa);
                A.oldal = 1;
                A.q = '';
                A.kijelolt = {};
                A.sorok = [];
                tarol('sdh-level-fiok', A.fiok);
                betolt();
            }

            rajzolMappak(true);
            rajzolLista();
            rajzolOlvaso();

            var regi = document.querySelector('[data-sdh-level-toast="' + id + '"]');

            if (regi) {
                regi.remove();
            }
        }).catch(function (hiba) {
            if (hiba.adat && hiba.adat.zarva) {
                A.level = null;

                return frissitFiokok();
            }

            A.level = null;
            elOlvaso.innerHTML = '<p class="sdh-level__ures sdh-level__ures--olvaso is-hiba">' + e(hiba.message) + '</p>';

            if (hiba.adat && hiba.adat.eltunt) {
                betolt(true);
            }
        });
    }

    gyoker.sdhMegnyit = function (id) {
        id = parseInt(id, 10);

        // Ha a levél nincs a most látható listában, a saját fiókja és mappája töltődik mellé (lásd olvas()).
        if (!A.sorok.some(function (s) { return s.id === id; })) {
            A.keres = (A.keres || 0) + 1;
            A.mappa = '';
            A.sorok = [];
            A.tolt = false;
        }

        olvas(id);
    };

    /* ---------------------------------------------------------------- */
    /* Műveletek                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Művelet a leveleken. A lista AZONNAL a művelet utáni állapotot mutatja (a törölt levél eltűnik,
     * a csillag átvált), a levelezőszerver a háttérben követi; ha ott nem sikerül, a lista visszaáll, és hibát jelez.
     */
    function muvelet(nev, idk, cel) {
        if (!idk.length) {
            return Promise.resolve();
        }

        var mezok = { muvelet: nev, idk: idk.join(',') };
        var erintett = {};
        var eltunik = ['kuka', 'vegleg', 'archiv', 'spam', 'nem_spam', 'athelyez'].indexOf(nev) >= 0;

        if (cel) {
            mezok.cel = cel;
        }

        idk.forEach(function (id) { erintett[String(id)] = true; });

        uzen('');
        A.megerosit = '';
        A.tolt = true;
        A.toltSzoveg = 'Mentés…';

        // --- azonnali rajz ---
        if (eltunik) {
            A.sorok = A.sorok.filter(function (s) { return !erintett[String(s.id)]; });
            A.ossz = Math.max(0, A.ossz - idk.length);
            A.kijelolt = {};
            idk.forEach(function (id) { delete levelTar[id]; });

            if (A.level && erintett[String(A.level.id)]) {
                A.level = null;
            }
        } else {
            A.sorok.forEach(function (s) {
                if (!erintett[String(s.id)]) {
                    return;
                }

                if (nev === 'olvasott' || nev === 'olvasatlan') {
                    s.olvasott = nev === 'olvasott';
                } else {
                    s.csillag = nev === 'csillag';
                }
            });

            if (A.level && erintett[String(A.level.id)]) {
                if (nev === 'olvasatlan') {
                    A.level = null;
                } else if (nev === 'csillag' || nev === 'csillag_le') {
                    A.level.csillag = nev === 'csillag';
                }
            }
        }

        listaTar = {};
        rajzolLista();
        rajzolOlvaso();

        return kuld('muvelet', mezok).then(function (adat) {
            A.fiokok = adat.fiokok || A.fiokok;

            // A helyére lépő levelek (a következő oldalról) a szerver gyorsítótárából jönnek, újabb szinkron nélkül.
            return betolt(true, true);
        }).catch(function (hiba) {
            uzen(hiba.message, 'hiba');

            return betolt(true);
        });
    }

    /** Mappaválasztó az áthelyezéshez (az app.js közös, lapozható választója). */
    function athelyezValaszto(idk, fiokKulcs, honnan) {
        var f = fiok(fiokKulcs);
        var mappak = (f ? f.mappak : []).filter(function (m) {
            return m.valaszthato && String(m.id) !== String(honnan) && ['flagged', 'important', 'drafts', 'sent'].indexOf(m.szerep) < 0;
        });

        if (!app().valaszto) {
            return;
        }

        app().valaszto(gyoker, {
            cim: 'Áthelyezés – hová?',
            alcim: idk.length + ' levél kerül át a kiválasztott mappába.',
            helyorzo: 'Mappa neve…',
            egyseg: 'mappa',
            oszlopok: [['Mappa', ''], ['Levél', '6rem', true]],
            forras: function (q) {
                var kerdes = q.trim().toLowerCase();

                return Promise.resolve(mappak.filter(function (m) { return kerdes === '' || m.teljes.toLowerCase().indexOf(kerdes) >= 0; }));
            },
            cellak: function (m) { return [e(m.szerep ? m.nev : m.teljes), String(m.osszes)]; },
            valaszt: function (m) { muvelet('athelyez', idk, m.id); }
        });
    }

    function ir(mod, id, fiokKulcs) {
        if (!app().nyit) {
            return;
        }

        app().nyit('level', id || 0, {
            parameterek: { mod: mod, fiok: fiokKulcs || A.fiok || '' },
            siker: function (adat) {
                toast({ cim: adat.uzenet, szoveg: '' });
                betolt(true);
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Események                                                        */
    /* ---------------------------------------------------------------- */

    gyoker.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;
        var gomb = cel.closest('[data-l]');
        var nev = gomb ? gomb.getAttribute('data-l') : '';

        // Bármi más kattintás visszavonja a megerősítésre váró törlést.
        var megerositett = A.megerosit;

        if (megerositett && !cel.closest('.is-megerosit')) {
            A.megerosit = '';
        }

        if (nev === 'uj') {
            ir('uj', 0);
        } else if (nev === 'frissit') {
            gomb.disabled = true;
            kuld('mappak', { fiok: A.fiok }).then(function (adat) {
                A.fiokok = adat.fiokok || A.fiokok;
            }).catch(function (hiba) {
                uzen(hiba.message, 'hiba');
            }).then(function () {
                gomb.disabled = false;

                return betolt();
            });
        } else if (nev === 'rendezo') {
            if (A.mappa === 'fontos' || !A.mappa) {
                uzen('A rendező ügynökhöz előbb nyiss meg egy mappát.', 'hiba');
            } else if (app().nyit) {
                uzen('');
                app().nyit('levelrendezo', 0, { parameterek: { fiok: A.fiok, mappa: A.mappa, idk: kijeloltIdk().join(',') } });
            }
        } else if (nev === 'ertesites') {
            window.Notification.requestPermission().then(function () {
                elErtesites.hidden = window.Notification.permission !== 'default';
            });
        } else if (nev === 'keresestorol') {
            A.q = '';
            A.oldal = 1;
            gyoker.querySelector('[data-l-kereso] input').value = '';
            betolt();
        } else if (nev === 'zar') {
            kuld('zar', { fiok: gomb.getAttribute('data-fiok') }).then(function () {
                window.location.reload();
            });
        } else if (nev === 'ujmappa') {
            A.ujMappa = gomb.getAttribute('data-fiok');
            rajzolMappak();
        } else if (nev === 'atnevez') {
            A.atnevez = true;
            rajzolLista();
        } else if (nev === 'mappatorol') {
            if (megerositett !== 'mappatorol') {
                A.megerosit = 'mappatorol';
                rajzolLista();
            } else {
                A.megerosit = '';
                kuld('mappa', { fiok: A.fiok, muvelet: 'torol', mappa: A.mappa }).then(function (adat) {
                    A.fiokok = adat.fiokok;

                    var be = szerepMappa('inbox');

                    valt(A.fiok, be ? be.id : '');
                }).catch(function (hiba) {
                    uzen(hiba.message, 'hiba');
                    rajzolLista();
                });
            }
        } else if (nev === 'kepek' && A.level) {
            olvas(A.level.id, true);
        } else if (nev === 'elintezve' && A.level) {
            var be = !A.level.elintezve;

            kuld('elintezve', { id: A.level.id, be: be ? 1 : 0 }).then(function () {
                A.level.elintezve = be;
                rajzolOlvaso();
                allapot();
                betolt(true);
            });
        }

        if (gomb) {
            return;
        }

        var ful = cel.closest('[data-l-ful]');

        if (ful) {
            if (ful.getAttribute('data-l-ful') !== A.fiok) {
                fulre(ful.getAttribute('data-l-ful'));
            }

            return;
        }

        var mappaGomb = cel.closest('[data-l-mappa]');

        if (mappaGomb) {
            valt(mappaGomb.getAttribute('data-fiok') || A.fiok, mappaGomb.getAttribute('data-l-mappa'));

            return;
        }

        var lap = cel.closest('[data-l-lap]');

        if (lap) {
            A.oldal = Math.max(1, Math.min(A.oldalak, A.oldal + parseInt(lap.getAttribute('data-l-lap'), 10)));
            A.kijelolt = {};
            betolt();

            return;
        }

        var csillag = cel.closest('[data-l-csillag]');

        if (csillag) {
            esemeny.stopPropagation();
            muvelet(csillag.classList.contains('is-be') ? 'csillag_le' : 'csillag', [csillag.getAttribute('data-l-csillag')]);

            return;
        }

        var listaMuv = cel.closest('[data-l-muv]');

        if (listaMuv) {
            var m = listaMuv.getAttribute('data-l-muv');
            var idk = kijeloltIdk();

            if (m === 'athelyez') {
                athelyezValaszto(idk, A.fiok, A.mappa);
            } else if (m === 'vegleg' && megerositett !== 'vegleg') {
                A.megerosit = 'vegleg';
                rajzolLista();
            } else {
                muvelet(m, idk);
            }

            return;
        }

        var egy = cel.closest('[data-l-egy]');

        if (egy && A.level) {
            var em = egy.getAttribute('data-l-egy');

            if (em === 'athelyez') {
                athelyezValaszto([A.level.id], A.level.fiok, A.level.mappa);
            } else if (em === 'vegleg' && megerositett !== 'egyvegleg') {
                A.megerosit = 'egyvegleg';
                rajzolOlvaso();
            } else {
                muvelet(em, [A.level.id]);
            }

            return;
        }

        var iro = cel.closest('[data-l-ir]');

        if (iro && A.level) {
            ir(iro.getAttribute('data-l-ir'), A.level.id, A.level.fiok);

            return;
        }

        if (megerositett && !A.megerosit) {
            rajzolLista();
            rajzolOlvaso();
        }

        if (cel.closest('[data-l-pipa]') || cel.closest('.sdh-level__mind')) {
            return;
        }

        var sor = cel.closest('[data-l-sor]');

        if (sor) {
            olvas(parseInt(sor.getAttribute('data-l-sor'), 10));
        }
    });

    gyoker.addEventListener('change', function (esemeny) {
        var cel = esemeny.target;

        if (cel.matches('[data-l-pipa]')) {
            A.kijelolt[cel.getAttribute('data-l-pipa')] = cel.checked;
            rajzolLista();
        } else if (cel.matches('[data-l-mind]')) {
            A.kijelolt = {};

            if (cel.checked) {
                A.sorok.forEach(function (s) { A.kijelolt[s.id] = true; });
            }

            rajzolLista();
        }
    });

    gyoker.addEventListener('keydown', function (esemeny) {
        var sor = esemeny.target.matches && esemeny.target.matches('[data-l-sor]') ? esemeny.target : null;

        if (sor && (esemeny.key === 'Enter' || esemeny.key === ' ')) {
            esemeny.preventDefault();
            olvas(parseInt(sor.getAttribute('data-l-sor'), 10));
        }
    });

    gyoker.addEventListener('submit', function (esemeny) {
        var urlap = esemeny.target;

        esemeny.preventDefault();

        if (urlap.matches('[data-l-kereso]')) {
            if (A.mappa === 'fontos' || !A.mappa) {
                return;
            }

            A.q = urlap.querySelector('input').value.trim();
            A.oldal = 1;
            A.kijelolt = {};
            betolt();
        } else if (urlap.matches('[data-l-nyit]')) {
            var hibaHely = urlap.querySelector('.sdh-level__zarhiba');
            var kulcs = urlap.getAttribute('data-l-nyit');

            hibaHely.textContent = '';

            kuld('nyit', { fiok: kulcs, jelszo: urlap.querySelector('input').value }).then(function (adat) {
                A.fiokok = adat.fiokok;
                valt(kulcs, (szerepMappa('inbox', kulcs) || {}).id || '');
            }).catch(function (hiba) {
                hibaHely.textContent = hiba.message;
                urlap.querySelector('input').select();
            });
        } else if (urlap.matches('[data-l-ujmappa]')) {
            var fk = urlap.getAttribute('data-l-ujmappa');

            kuld('mappa', { fiok: fk, muvelet: 'uj', nev: urlap.querySelector('input').value }).then(function (adat) {
                A.fiokok = adat.fiokok;
                A.ujMappa = '';
                rajzolMappak();
            }).catch(function (hiba) {
                uzen(hiba.message, 'hiba');
            });
        } else if (urlap.matches('[data-l-atnevez]')) {
            kuld('mappa', { fiok: A.fiok, muvelet: 'atnevez', mappa: A.mappa, nev: urlap.querySelector('input').value }).then(function (adat) {
                A.fiokok = adat.fiokok;
                A.atnevez = false;
                rajzolMappak();
                rajzolLista();
            }).catch(function (hiba) {
                uzen(hiba.message, 'hiba');
            });
        }
    });

    gyoker.addEventListener('keydown', function (esemeny) {
        if (esemeny.key === 'Escape' && (A.ujMappa || A.atnevez)) {
            A.ujMappa = '';
            A.atnevez = false;
            rajzolMappak();
            rajzolLista();
        }
    });

    // A rendező ügynök végzett: a mappák és a lista a szerver (most igazított) gyorsítótárából frissül.
    document.addEventListener('sdh:level-rendezve', function (esemeny) {
        A.fiokok = (esemeny.detail && esemeny.detail.fiokok) || A.fiokok;
        A.kijelolt = {};
        listaTar = {};
        levelTar = {};
        A.level = null;
        rajzolOlvaso();
        rajzolMappak();
        betolt(true, true);
    });

    // Új levél érkezett: ha épp egy Beérkezett mappa első oldalát nézed (kijelölés nélkül), a lista magától frissül.
    document.addEventListener('sdh:level-allapot', function (esemeny) {
        var adat = esemeny.detail || {};
        var m = mappa();

        // Fiókonkénti számlálók (olvasatlan, azonnali teendő) a lapfülekre és a „Teendők" sorra.
        (adat.fiokok || []).forEach(function (friss) {
            var f = fiok(friss.kulcs);

            if (f) {
                f.olvasatlan = friss.olvasatlan || 0;
                f.azonnal = friss.azonnal || 0;
            }
        });

        rajzolFulek();

        var jel = elMappak.querySelector('.sdh-level__mappa--teendo .sdh-level__db');
        var sajat = (fiok() || {}).azonnal || 0;

        if (jel) {
            jel.textContent = String(sajat);
            jel.hidden = !(sajat > 0);
            jel.classList.toggle('is-azonnal', sajat > 0);
        }

        // Csak a most megnyitott fiók új levele frissíti a listát – a másik fióké a saját lapfülén jelez.
        var ide = (adat.ujak || []).filter(function (u) { return u.fiok === A.fiok; });

        // A szerver az imént szinkronizált: elég a gyorsítótárából újratölteni (újabb kapcsolódás nélkül).
        if (ide.length && !A.tolt && A.oldal === 1 && !A.q && !kijeloltIdk().length && (A.mappa === 'fontos' || (m && m.szerep === 'inbox'))) {
            betolt(true, true);
        }
    });

    /* ---------------------------------------------------------------- */
    /* Indulás                                                          */
    /* ---------------------------------------------------------------- */

    if (window.Notification && window.Notification.permission === 'default') {
        elErtesites.hidden = false;
    }

    rajzolMappak();
    rajzolLista();
    rajzolOlvaso();
    magassag();

    var kert = (window.location.hash.match(/level=(\d+)/) || [])[1];
    // A legutóbb nézett fiók lapja nyílik meg (ha nincs zárolva); különben az első nyitott fiók.
    var mentett = fiok(tarol('sdh-level-fiok') || '-');
    var elso = (mentett && !mentett.zarva ? mentett : null) || A.fiokok.filter(function (f) { return !f.zarva; })[0] || A.fiokok[0];

    if (kert) {
        // Értesítésből érkezve: a levél nyílik meg, a mappája mellé töltődik.
        A.mappa = '';
        olvas(parseInt(kert, 10));
    } else if (elso) {
        fulre(elso.kulcs);
    }
}());
