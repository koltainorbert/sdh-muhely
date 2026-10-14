/**
 * Mezőmemória és Gyakori tételek (0.63.0) – SDH_Muhely_Emlek.
 *
 * MEZŐMEMÓRIA: minden szövegmező megjegyzi, mit írtak bele vagy mire
 * kerestek, és gépelés közben felajánlja (ugyanúgy, mint a munkalap az
 * ügyfél nevét). Nyíl fel/le: választás, Enter: beírás, Esc: bezár, a sor
 * végi ×: kiveszi a felajánlásból. Üres mezőben a lefelé nyíl mindet mutatja.
 *
 *  - A memória a szerveren van, közös (mezőkulcsonként): pl. „szamla:vevo[cim]".
 *    A data-sdh-emlek="…" kulcsot adhat (a számla kézi tétele: „tetelnev").
 *  - Ahol saját felajánló lista van (ügyfél-, szolgáltatás-, termékválasztó,
 *    irányítószám, címzett, IMEI), oda nem lép be.
 *  - Jelszó, kulcs, kód, kártya- és bankszámlaszám soha nem kerül bele
 *    (a szerver is szűr).
 *
 * GYAKORI TÉTELEK: a számla ablakában a „Gyakori tételek…" gomb popupja –
 * a Leggyakoribb Számla tételek listája: kattintásra kézi tételként a
 * számlára kerül; ugyanott átírható, törölhető, sorrendezhető, bővíthető.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};
    var E = B.emlek || {};
    var APP = function () { return window.SDH_MUHELY_APP || {}; };

    /* ================================================================ */
    /* Közös                                                             */
    /* ================================================================ */

    function kuld(muvelet, adat) {
        var fd = new FormData();

        fd.append('action', muvelet);
        fd.append('_ajax_nonce', B.nonce || '');

        Object.keys(adat || {}).forEach(function (k) {
            var v = adat[k];

            if (Array.isArray(v)) {
                v.forEach(function (x) { fd.append(k + '[]', x); });
            } else {
                fd.append(k, v);
            }
        });

        return fetch(B.ajax || window.ajaxurl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (v) { return v.json(); });
    }

    function elem(tag, osztaly, szoveg) {
        var el = document.createElement(tag);

        if (osztaly) {
            el.className = osztaly;
        }

        if (szoveg !== undefined) {
            el.textContent = szoveg;
        }

        return el;
    }

    /** Kisbetű, ékezet nélkül – a kereséshez. */
    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }

    /* ================================================================ */
    /* Mezőmemória                                                       */
    /* ================================================================ */

    var TITKOS = null;

    try {
        TITKOS = new RegExp(E.titkos || '(jelsz|passw|kulcs|token|titok)', 'i');
    } catch (x) {
        TITKOS = /(jelsz|passw|kulcs|token|titok)/i;
    }

    // Saját felajánló listájuk van – ide a memória nem lép be.
    var SAJAT_LISTA = [
        '[data-sdh-pop]', '[data-sdh-isz]', '[data-sdh-telepules]', '[data-sdh-szolg]', '[data-sdh-szolgval-kereso]',
        '[data-sdh-termek]', '[data-sdh-termval-kereso]', '[data-sdh-szval-kereso]', '[data-sdh-imei]', '[data-sdh-tovabbi-dij]',
        '.sdh-valaszto__mezo', '[data-v="nev"]', '[data-v="adoszam"]', '[aria-autocomplete]', '[role="combobox"]',
        '[data-sdh-leveliro] input[name="cimzett"]', '[data-sdh-leveliro] input[name="masolat"]', '[data-sdh-leveliro] input[name="titkos"]'
    ].join(',');

    var KIZART_TEREP = '.sdh-ai, .sdh-ub, .sdh-sugo-kereso, .sdh-emlek-lista, .sdh-valaszto, [data-sdh-emlek-ki]';
    var TIPUSOK = ['text', 'search', 'email', 'tel', 'url', ''];

    function oldalKulcs() {
        try {
            var p = new URLSearchParams(window.location.search);

            return p.get('muhely') || (p.get('page') || '').replace(/^sdh-muhely-?/, '') || 'oldal';
        } catch (x) {
            return 'oldal';
        }
    }

    /** A mező memóriakulcsa, vagy '' ha a mező nem jegyezhet meg semmit. */
    function kulcs(mezo) {
        if (!mezo || mezo.tagName !== 'INPUT' || mezo.readOnly || mezo.disabled || mezo.hasAttribute('list')) {
            return '';
        }

        var sajat = mezo.getAttribute('data-sdh-emlek');

        if (sajat) {
            return TITKOS.test(sajat) ? '' : sajat;
        }

        if (TIPUSOK.indexOf((mezo.getAttribute('type') || '').toLowerCase()) === -1) {
            return '';
        }

        if (mezo.matches(SAJAT_LISTA) || mezo.closest(KIZART_TEREP)) {
            return '';
        }

        var ac = (mezo.getAttribute('autocomplete') || '').toLowerCase();

        if (ac === 'new-password' || ac === 'current-password' || ac === 'one-time-code' || ac.indexOf('cc-') === 0) {
            return '';
        }

        var nev = mezo.name || mezo.id || '';

        if (!nev) {
            return '';
        }

        var felirat = [nev, mezo.id, mezo.placeholder, mezo.getAttribute('aria-label'), mezo.title].join(' ');

        if (TITKOS.test(felirat)) {
            return '';
        }

        var dialog = mezo.closest('dialog');
        var urlap = mezo.closest('form');
        var ter = (dialog && dialog.getAttribute('data-sdh-modul')) ||
            (urlap && urlap.getAttribute('data-sdh-ajax-action')) || oldalKulcs();

        return (ter + ':' + nev).toLowerCase().replace(/\[\d+\]|\[__i__\]/g, '[]').replace(/[^a-z0-9:._\-[\]]/g, '').slice(0, 120);
    }

    /* ---- Gyorsítótár: mezőkulcsonként egyszer kér, utána helyben szűr ---- */

    var tar = {};

    function ertekek(k) {
        var t = tar[k];

        if (t && t.adat && Date.now() - t.ido < 60000) {
            return Promise.resolve(t.adat);
        }

        if (t && t.igeret) {
            return t.igeret;
        }

        tar[k] = { ido: Date.now(), adat: t ? t.adat : null };
        tar[k].igeret = kuld('sdh_muhely_emlek_ker', { kulcs: k }).then(function (v) {
            var lista = v && v.success && v.data && Array.isArray(v.data.ertekek) ? v.data.ertekek : [];

            tar[k] = { ido: Date.now(), adat: lista };

            return lista;
        }).catch(function () {
            tar[k] = { ido: Date.now(), adat: [] };

            return [];
        });

        return tar[k].igeret;
    }

    function tarbaFelvesz(k, ertek) {
        var t = tar[k];

        if (!t || !t.adat) {
            return;
        }

        var n = norm(ertek);

        t.adat = t.adat.filter(function (x) { return norm(x.e) !== n || x.f; });

        if (!t.adat.some(function (x) { return norm(x.e) === n; })) {
            var kedvencek = t.adat.filter(function (x) { return x.f; });

            t.adat = kedvencek.concat([{ e: ertek }], t.adat.filter(function (x) { return !x.f; }));
        }
    }

    /* ---- Rögzítés: a beírt érték egy kis késleltetéssel megy a szerverre ---- */

    var sor = [];
    var sorIdo = 0;

    function rogzit(mezo) {
        var k = kulcs(mezo);
        var ertek = String(mezo.value || '').replace(/\s+/g, ' ').trim();

        if (!E.be || !k || ertek.length < 2 || ertek.length > 190 || /^\d{13,19}$/.test(ertek.replace(/[\s-]/g, ''))) {
            return;
        }

        var tetel = k + '\t' + ertek;

        if (sor.indexOf(tetel) === -1) {
            sor.push(tetel);
        }

        tarbaFelvesz(k, ertek);
        window.clearTimeout(sorIdo);
        sorIdo = window.setTimeout(urit, 700);
    }

    function urit() {
        if (!sor.length) {
            return;
        }

        var tetelek = sor.splice(0, 40);

        kuld('sdh_muhely_emlek_ment', { tetelek: tetelek }).catch(function () { /* kényelmi funkció: hibánál kimarad */ });
    }

    window.addEventListener('pagehide', function () {
        if (!sor.length || !navigator.sendBeacon) {
            return;
        }

        var fd = new FormData();

        fd.append('action', 'sdh_muhely_emlek_ment');
        fd.append('_ajax_nonce', B.nonce || '');
        sor.splice(0, 40).forEach(function (t) { fd.append('tetelek[]', t); });
        navigator.sendBeacon(B.ajax || '/wp-admin/admin-ajax.php', fd);
    });

    /* ---- A lenyíló lista ---- */

    var lista = null;      // a lista eleme
    var aktivMezo = null;  // amelyik mezőhöz nyitva van
    var aktivKulcs = '';
    var talalatok = [];
    var kijelolt = -1;
    var keresIdo = 0;

    function bezar() {
        if (lista) {
            lista.remove();
        }

        lista = null;
        aktivMezo = null;
        talalatok = [];
        kijelolt = -1;
    }

    function szur(adat, q) {
        var n = norm(q);
        var ki;

        if (!n) {
            ki = adat.slice();
        } else {
            ki = adat.filter(function (x) {
                var e = norm(x.e);

                return e !== n && e.indexOf(n) !== -1;
            });

            // Ami a beírt szöveggel kezdődik, az áll elöl; azon belül a gyakori tétel.
            ki.sort(function (a, b) {
                var ae = norm(a.e).indexOf(n) === 0 ? 0 : 1;
                var be = norm(b.e).indexOf(n) === 0 ? 0 : 1;

                return ae - be || (b.f ? 1 : 0) - (a.f ? 1 : 0);
            });
        }

        return ki.slice(0, 8);
    }

    function rajzol(mezo) {
        if (!talalatok.length) {
            bezar();

            return;
        }

        var dialog = mezo.closest('dialog');
        var hely = dialog || document.body;

        if (!lista || lista.parentNode !== hely) {
            if (lista) {
                lista.remove();
            }

            lista = elem('div', 'sdh-emlek-lista');
            lista.setAttribute('data-sdh-emlek-lista', '');
            hely.appendChild(lista);

            // A lista nem veszi el a fókuszt a mezőtől.
            lista.addEventListener('mousedown', function (esemeny) {
                esemeny.preventDefault();
            });

            lista.addEventListener('click', listaKatt);
        }

        aktivMezo = mezo;
        lista.innerHTML = '';

        var q = norm(mezo.value);

        talalatok.forEach(function (x, i) {
            var s = elem('div', 'sdh-emlek-lista__sor' + (i === kijelolt ? ' is-kijelolt' : '') + (x.f ? ' is-kedvenc' : ''));
            var szoveg = elem('span', 'sdh-emlek-lista__szoveg');
            var e = String(x.e);
            var hol = q ? norm(e).indexOf(q) : -1;

            s.setAttribute('data-i', String(i));

            // A beírt rész kiemelve (ékezetfüggetlen keresés: a hossz a normalizált alakkal egyezik).
            if (hol >= 0 && norm(e).length === e.length) {
                szoveg.appendChild(document.createTextNode(e.slice(0, hol)));
                szoveg.appendChild(elem('mark', '', e.slice(hol, hol + q.length)));
                szoveg.appendChild(document.createTextNode(e.slice(hol + q.length)));
            } else {
                szoveg.textContent = e;
            }

            if (x.f) {
                s.appendChild(elem('span', 'sdh-emlek-lista__csillag', '★'));
            }

            s.appendChild(szoveg);

            if (!x.f) {
                var torol = elem('button', 'sdh-emlek-lista__torol', '×');

                torol.type = 'button';
                torol.tabIndex = -1;
                torol.title = 'Kivétel a felajánlásból';
                torol.setAttribute('aria-label', 'Kivétel a felajánlásból: ' + e);
                torol.setAttribute('data-torol', String(i));
                s.appendChild(torol);
            }

            lista.appendChild(s);
        });

        helyez(mezo);
    }

    /** A lista a mező alatt (fixen; a popup zoomját örökölve), ha alul nincs hely, fölötte. */
    function helyez(mezo) {
        if (!lista) {
            return;
        }

        var dialog = mezo.closest('dialog');
        var z = dialog ? parseFloat(dialog.style.zoom || '1') || 1 : 1;
        var r = mezo.getBoundingClientRect();

        // A lista a popupban áll, így a popup zoomját örökli: a koordinátákat csak vissza kell osztani.
        lista.style.minWidth = Math.max(180, r.width / z) + 'px';
        lista.style.left = (r.left / z) + 'px';

        var magas = lista.getBoundingClientRect().height;

        if (r.bottom + magas + 8 > window.innerHeight && r.top - magas - 8 > 0) {
            lista.style.top = ((r.top - magas - 3) / z) + 'px';
        } else {
            lista.style.top = ((r.bottom + 3) / z) + 'px';
        }
    }

    function valaszt(i) {
        var x = talalatok[i];
        var mezo = aktivMezo;

        if (!x || !mezo) {
            return;
        }

        mezo.value = x.e;
        bezar();
        mezo.dispatchEvent(new Event('input', { bubbles: true }));
        mezo.dispatchEvent(new Event('change', { bubbles: true }));
        mezo.focus();
    }

    function listaKatt(esemeny) {
        var torol = esemeny.target.closest('[data-torol]');

        if (torol) {
            var x = talalatok[parseInt(torol.getAttribute('data-torol'), 10)];

            if (x) {
                kuld('sdh_muhely_emlek_torol', { kulcs: aktivKulcs, ertek: x.e }).catch(function () {});

                if (tar[aktivKulcs] && tar[aktivKulcs].adat) {
                    tar[aktivKulcs].adat = tar[aktivKulcs].adat.filter(function (y) { return y.e !== x.e; });
                }

                talalatok.splice(talalatok.indexOf(x), 1);
                kijelolt = Math.min(kijelolt, talalatok.length - 1);
                rajzol(aktivMezo);
            }

            return;
        }

        var s = esemeny.target.closest('[data-i]');

        if (s) {
            valaszt(parseInt(s.getAttribute('data-i'), 10));
        }
    }

    function keres(mezo, mindet) {
        var k = kulcs(mezo);

        if (!E.be || !k) {
            return;
        }

        var q = mezo.value;

        if (!mindet && !String(q).trim()) {
            bezar();

            return;
        }

        aktivKulcs = k;

        ertekek(k).then(function (adat) {
            if (document.activeElement !== mezo || (mezo.value !== q && !mindet)) {
                return;
            }

            talalatok = szur(adat, mindet ? '' : q);
            kijelolt = -1;
            rajzol(mezo);
        });
    }

    /* ---- Események ---- */

    document.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target;

        if (!esemeny.isTrusted || !kulcs(mezo)) {
            return;
        }

        window.clearTimeout(keresIdo);
        keresIdo = window.setTimeout(function () { keres(mezo, false); }, 90);
    });

    document.addEventListener('focusin', function (esemeny) {
        var mezo = esemeny.target;

        if (mezo !== aktivMezo) {
            bezar();
        }

        // Üresen is felajánl, ahol ez kért (pl. a számla tételneve: a Gyakori tételek).
        if (mezo && mezo.hasAttribute && mezo.hasAttribute('data-sdh-emlek-fokusz') && !String(mezo.value).trim() && kulcs(mezo)) {
            keres(mezo, true);
        } else if (kulcs(mezo)) {
            ertekek(kulcs(mezo)); // előre betölt: gépeléskor már helyben szűr
        }
    });

    document.addEventListener('focusout', function (esemeny) {
        if (esemeny.target === aktivMezo) {
            window.setTimeout(function () {
                if (document.activeElement !== aktivMezo) {
                    bezar();
                }
            }, 120);
        }
    });

    document.addEventListener('change', function (esemeny) {
        if (kulcs(esemeny.target)) {
            rogzit(esemeny.target);
        }
    });

    document.addEventListener('keydown', function (esemeny) {
        var mezo = esemeny.target;

        if (!mezo || !mezo.matches || !mezo.matches('input')) {
            return;
        }

        var nyitva = lista && aktivMezo === mezo && talalatok.length;

        if (esemeny.key === 'ArrowDown' && !nyitva && kulcs(mezo)) {
            esemeny.preventDefault();
            keres(mezo, true);

            return;
        }

        if (!nyitva) {
            // Keresőmezőben az Enter is rögzít (ott nincs „change" mentés).
            if (esemeny.key === 'Enter' && kulcs(mezo)) {
                rogzit(mezo);
            }

            return;
        }

        if (esemeny.key === 'ArrowDown' || esemeny.key === 'ArrowUp') {
            esemeny.preventDefault();
            kijelolt = (kijelolt + (esemeny.key === 'ArrowDown' ? 1 : -1) + talalatok.length + 1) % (talalatok.length + 1);

            if (kijelolt === talalatok.length) {
                kijelolt = -1;
            }

            rajzol(mezo);
        } else if (esemeny.key === 'Enter' && kijelolt >= 0) {
            esemeny.preventDefault();
            esemeny.stopPropagation();
            valaszt(kijelolt);
        } else if (esemeny.key === 'Escape') {
            // Előbb a lista zárul, nem a popup.
            esemeny.preventDefault();
            esemeny.stopPropagation();
            bezar();
        } else if (esemeny.key === 'Tab') {
            bezar();
        }
    }, true);

    // Görgetésnél / méretezésnél a lista a helyén marad.
    window.addEventListener('scroll', function (esemeny) {
        if (lista && !(esemeny.target && esemeny.target.closest && esemeny.target.closest('.sdh-emlek-lista'))) {
            helyez(aktivMezo);
        }
    }, true);

    window.addEventListener('resize', function () {
        if (lista && aktivMezo) {
            helyez(aktivMezo);
        }
    });

    /* ================================================================ */
    /* Leggyakoribb Számla tételek listája                                */
    /* ================================================================ */

    var OLDAL = 10;
    var ZAR_SVG = '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M3.6 3.6l4.8 4.8M8.4 3.6l-4.8 4.8"/></svg>';

    function gyakoriNyit(gomb) {
        var urlap = gomb.closest('[data-sdh-szamlaurlap]');
        var lista2 = [];
        var oldal = 0;
        var szerk = -1;

        if (!urlap) {
            return;
        }

        try {
            lista2 = JSON.parse(gomb.getAttribute('data-sdh-lista') || '[]') || [];
        } catch (x) {
            lista2 = [];
        }

        var dialog = elem('dialog', 'sdh-modal sdh-modal--pop sdh-gyh sdh-gyt');

        dialog.innerHTML = '<div class="sdh-modal__doboz">' +
            '<button type="button" class="sdh-modal__bezar" aria-label="Bezárás" title="Bezárás">' + ZAR_SVG + '</button>' +
            '<div class="sdh-modal__torzs">' +
            '  <h2 class="sdh-modal__cim">Leggyakoribb Számla tételek listája</h2>' +
            '  <p class="sdh-modal__alcim">Kattints a tételre: kézi tételként a számlára kerül, az árát te írod be. ✎ átírja, × törli, a nyilak sorrendezik – minden azonnal mentődik.</p>' +
            '  <div class="sdh-gyt__lista" data-gyt-lista></div>' +
            '  <div class="sdh-gyh__lapozo" data-gyt-lapozo hidden>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-gyt-lap="-1" aria-label="Előző oldal">‹</button>' +
            '    <span data-gyt-oldal></span>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-gyt-lap="1" aria-label="Következő oldal">›</button>' +
            '  </div>' +
            '  <form class="sdh-gyh__uj" data-gyt-uj>' +
            '    <input type="text" maxlength="190" autocomplete="off" data-gyt-mezo data-sdh-emlek-ki aria-label="Új tétel neve" placeholder="Új tétel neve – pl. Laptop kijelzőcsere">' +
            '    <button type="submit" class="sdh-gomb sdh-gomb--vilagos"><span class="sdh-plusz" aria-hidden="true"></span>Felvétel a listára</button>' +
            '  </form>' +
            '  <div class="sdh-gyh__lab">' +
            '    <span class="sdh-mezo__sugo" data-gyt-uzenet role="status"></span>' +
            '    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-gyt-kesz>Kész</button>' +
            '  </div>' +
            '</div></div>';
        document.body.appendChild(dialog);

        var hely = dialog.querySelector('[data-gyt-lista]');
        var uzenet = dialog.querySelector('[data-gyt-uzenet]');

        function ment() {
            uzenet.textContent = 'Mentés…';

            // Minden nyitott számlaablak gombja is a friss listát kapja.
            Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-szamla-gyakori]'), function (g) {
                g.setAttribute('data-sdh-lista', JSON.stringify(lista2));
            });

            delete tar.tetelnev;

            return kuld('sdh_muhely_szamla_gyakori_ment', { lista: lista2.length ? lista2 : [''] }).then(function (v) {
                uzenet.textContent = v && v.success ? 'Elmentve.' : ((v && v.data && v.data.uzenet) || 'A mentés nem sikerült.');
            }).catch(function () {
                uzenet.textContent = 'A mentés nem sikerült (nincs kapcsolat).';
            });
        }

        function rajzolLista() {
            var oldalak = Math.max(1, Math.ceil(lista2.length / OLDAL));

            oldal = Math.max(0, Math.min(oldal, oldalak - 1));
            hely.innerHTML = '';

            if (!lista2.length) {
                hely.appendChild(elem('p', 'sdh-gyh__ures', 'A lista üres – írd be alul az első tételt.'));
            }

            lista2.slice(oldal * OLDAL, (oldal + 1) * OLDAL).forEach(function (nev, j) {
                var i = oldal * OLDAL + j;
                var s = elem('div', 'sdh-gyt__sor');

                s.setAttribute('data-gyt-i', String(i));

                if (i === szerk) {
                    var mezo = elem('input', 'sdh-gyt__szerk');

                    mezo.type = 'text';
                    mezo.maxLength = 190;
                    mezo.value = nev;
                    mezo.setAttribute('data-gyt-szerkmezo', '');
                    mezo.setAttribute('data-sdh-emlek-ki', '');
                    mezo.setAttribute('aria-label', 'A tétel új neve');
                    s.appendChild(mezo);
                    s.appendChild(gombElem('Kész', 'data-gyt-ok', 'sdh-gomb sdh-gomb--elsodleges sdh-gyt__kicsi', 'Átírás mentése'));
                } else {
                    var felvesz = elem('button', 'sdh-pill sdh-gyt__nev');

                    felvesz.type = 'button';
                    felvesz.setAttribute('data-gyt-felvesz', '');
                    felvesz.title = 'Felvétel a számlára: ' + nev;
                    felvesz.appendChild(elem('span', 'sdh-pill__cimke', nev));
                    s.appendChild(felvesz);
                    s.appendChild(gombElem('▲', 'data-gyt-fel', 'sdh-gyt__ikon', 'Feljebb'));
                    s.appendChild(gombElem('▼', 'data-gyt-le', 'sdh-gyt__ikon', 'Lejjebb'));
                    s.appendChild(gombElem('✎', 'data-gyt-atir', 'sdh-gyt__ikon', 'Átírás'));
                    s.appendChild(gombElem('×', 'data-gyt-torol', 'sdh-gyt__ikon sdh-gyt__ikon--torol', 'Törlés a listáról'));
                }

                hely.appendChild(s);
            });

            dialog.querySelector('[data-gyt-lapozo]').hidden = oldalak <= 1;
            dialog.querySelector('[data-gyt-oldal]').textContent = (oldal + 1) + ' / ' + oldalak;

            var szerkMezo = hely.querySelector('[data-gyt-szerkmezo]');

            if (szerkMezo) {
                szerkMezo.focus();
                szerkMezo.select();
            }
        }

        function gombElem(szoveg, attr, osztaly, cim) {
            var g = elem('button', osztaly, szoveg);

            g.type = 'button';
            g.setAttribute(attr, '');
            g.title = cim;
            g.setAttribute('aria-label', cim);

            return g;
        }

        function atirKesz() {
            var mezo = hely.querySelector('[data-gyt-szerkmezo]');

            if (!mezo || szerk < 0) {
                return;
            }

            var uj = mezo.value.replace(/\s+/g, ' ').trim();

            if (uj && !lista2.some(function (x, k) { return k !== szerk && norm(x) === norm(uj); })) {
                lista2[szerk] = uj;
                ment();
            }

            szerk = -1;
            rajzolLista();
        }

        function felvesz(nev) {
            var app = APP();
            var sor2 = app.szamlaSor ? app.szamlaSor(urlap, { tipus: 'szolgaltatas', megnevezes: nev, me: 'db', brutto_ar: 0 }) : null;

            dialog.close();

            if (sor2 && app.szamlaUjMezo) {
                var ar = app.szamlaUjMezo(sor2, 'brutto_ar');

                if (ar) {
                    ar.focus();
                }
            }
        }

        dialog.addEventListener('click', function (esemeny) {
            var cel = esemeny.target;

            if (cel === dialog || cel.closest('.sdh-modal__bezar') || cel.closest('[data-gyt-kesz]')) {
                dialog.close();

                return;
            }

            var s = cel.closest('[data-gyt-i]');
            var i = s ? parseInt(s.getAttribute('data-gyt-i'), 10) : -1;

            if (cel.closest('[data-gyt-lap]')) {
                oldal += parseInt(cel.closest('[data-gyt-lap]').getAttribute('data-gyt-lap'), 10);
                rajzolLista();
            } else if (i < 0) {
                return;
            } else if (cel.closest('[data-gyt-felvesz]')) {
                felvesz(lista2[i]);
            } else if (cel.closest('[data-gyt-fel]') || cel.closest('[data-gyt-le]')) {
                var cel2 = i + (cel.closest('[data-gyt-fel]') ? -1 : 1);

                if (cel2 >= 0 && cel2 < lista2.length) {
                    var t = lista2[i];

                    lista2[i] = lista2[cel2];
                    lista2[cel2] = t;
                    oldal = Math.floor(cel2 / OLDAL);
                    ment();
                    rajzolLista();
                }
            } else if (cel.closest('[data-gyt-atir]')) {
                szerk = i;
                rajzolLista();
            } else if (cel.closest('[data-gyt-ok]')) {
                atirKesz();
            } else if (cel.closest('[data-gyt-torol]')) {
                // Kétlépcsős: első kattintásra „Biztos?", a másodikra töröl.
                var g = cel.closest('[data-gyt-torol]');

                if (g.getAttribute('data-gyt-biztos') === '1') {
                    lista2.splice(i, 1);
                    ment();
                    rajzolLista();
                } else {
                    g.setAttribute('data-gyt-biztos', '1');
                    g.textContent = 'Törlöd?';
                    g.classList.add('is-biztos');
                    window.setTimeout(function () {
                        if (g.isConnected) {
                            g.removeAttribute('data-gyt-biztos');
                            g.textContent = '×';
                            g.classList.remove('is-biztos');
                        }
                    }, 2500);
                }
            }
        });

        dialog.addEventListener('keydown', function (esemeny) {
            if (esemeny.target.matches && esemeny.target.matches('[data-gyt-szerkmezo]')) {
                if (esemeny.key === 'Enter') {
                    esemeny.preventDefault();
                    atirKesz();
                } else if (esemeny.key === 'Escape') {
                    esemeny.preventDefault();
                    szerk = -1;
                    rajzolLista();
                }
            }
        });

        dialog.querySelector('[data-gyt-uj]').addEventListener('submit', function (esemeny) {
            esemeny.preventDefault();

            var mezo = dialog.querySelector('[data-gyt-mezo]');
            var uj = mezo.value.replace(/\s+/g, ' ').trim();

            if (!uj) {
                return;
            }

            if (lista2.some(function (x) { return norm(x) === norm(uj); })) {
                uzenet.textContent = 'Ez már rajta van a listán.';

                return;
            }

            lista2.push(uj);
            mezo.value = '';
            oldal = Math.floor((lista2.length - 1) / OLDAL);
            ment();
            rajzolLista();
        });

        dialog.addEventListener('close', function () {
            dialog.remove();
        });

        rajzolLista();
        dialog.showModal();
    }

    document.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-szamla-gyakori]') : null;

        if (gomb) {
            esemeny.preventDefault();
            gyakoriNyit(gomb);
        }
    });

    window.SDH_EMLEK = { kulcs: kulcs, rogzit: rogzit, urit: urit, gyakoriNyit: gyakoriNyit };
})();
