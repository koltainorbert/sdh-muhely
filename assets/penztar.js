/**
 * SDH Műhely – Házipénztár (0.35).
 *
 * Két része van:
 *  1. MINDEN CRM-oldalon: a munkalap „Fizetés" gombja, és a munkalap mentése
 *     / a számla kiállítása utáni ajánlat („bekerüljön a pénztárba?").
 *  2. A Pénztár oldal ([data-sdh-penztar]): lapfülek – Ma, Napló, KP (kassza
 *     számolása és zárás), Riport, Statisztika, Ügynök, Keresés – és az import.
 *
 * A szerver JSON-t ad, a felület itt rajzolódik; a felvitel és a fizetés
 * ablaka szerveroldali űrlap az app.js popupjában (méretezhető, teljes képernyő).
 * Minden szöveg, ami adatból jön, e()-n megy át.
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

    function e(szoveg) {
        return String(szoveg === null || szoveg === undefined ? '' : szoveg)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    var NBSP = ' ';
    var KESKENY = ' ';

    function ezres(n) {
        return String(Math.round(Math.abs(n))).replace(/\B(?=(\d{3})+(?!\d))/g, KESKENY);
    }

    /** 12 000 Ft – a negatív valódi mínuszjellel. */
    function penz(n, ures) {
        n = Number(n) || 0;

        if (ures && Math.round(n) === 0) {
            return '';
        }

        return (Math.round(n) < 0 ? '−' : '') + ezres(n) + NBSP + 'Ft';
    }

    function szam(n) {
        n = Number(n) || 0;

        return (Math.round(n) < 0 ? '−' : '') + ezres(n);
    }

    function elojeles(n) {
        n = Number(n) || 0;

        return (Math.round(n) > 0 ? '+' : '') + penz(n);
    }

    var HONAPOK = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
    var HO_ROVID = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
    var NAPOK = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];

    function datumObj(d) {
        var r = String(d || '').split('-');

        return new Date(Date.UTC(+r[0], (+r[1] || 1) - 1, +r[2] || 1));
    }

    function datumIso(o) {
        return o.getUTCFullYear() + '-' + String(o.getUTCMonth() + 1).padStart(2, '0') + '-' + String(o.getUTCDate()).padStart(2, '0');
    }

    function napEltol(d, n) {
        var o = datumObj(d);

        o.setUTCDate(o.getUTCDate() + n);

        return datumIso(o);
    }

    /** 2026. október 10., szombat */
    function datumHosszu(d) {
        var o = datumObj(d);

        return o.getUTCFullYear() + '. ' + HONAPOK[o.getUTCMonth()] + ' ' + o.getUTCDate() + '., ' + NAPOK[o.getUTCDay()];
    }

    /** okt. 10. szo */
    function datumRovid(d) {
        var o = datumObj(d);

        return HO_ROVID[o.getUTCMonth()] + ' ' + o.getUTCDate() + '.';
    }

    function hetnap(d) {
        return NAPOK[datumObj(d).getUTCDay()];
    }

    function kuld(akcio, mezok, fajl, haladas) {
        var adat = new FormData();

        adat.set('action', 'sdh_muhely_penztar_' + akcio);
        adat.set('_wpnonce', B.nonce || '');

        Object.keys(mezok || {}).forEach(function (nev) {
            var v = mezok[nev];

            if (Array.isArray(v)) {
                v.forEach(function (x) { adat.append(nev + '[]', x); });
            } else if (v !== undefined && v !== null) {
                adat.set(nev, v);
            }
        });

        if (fajl) {
            adat.set('fajl', fajl);
        }

        var url = new URL(B.ajax, window.location.origin).toString();

        // Feltöltésnél XHR, mert a fetch nem ad folyamatjelzést.
        if (fajl && haladas) {
            return new Promise(function (ok, hiba) {
                var xhr = new XMLHttpRequest();

                xhr.open('POST', url);
                xhr.withCredentials = true;
                xhr.upload.onprogress = function (ev) {
                    if (ev.lengthComputable) {
                        haladas(ev.loaded / ev.total);
                    }
                };
                xhr.onload = function () {
                    var json = null;

                    try {
                        json = JSON.parse(xhr.responseText);
                    } catch (x) {
                        hiba(new Error(xhr.status === 413 ? 'A fájl nagyobb, mint amit a szerver fogad.' : 'A szerver nem várt választ adott (' + xhr.status + ').'));

                        return;
                    }

                    if (!json || !json.success) {
                        hiba(new Error((json && json.data && json.data.uzenet) || 'A művelet nem sikerült.'));

                        return;
                    }

                    ok(json.data);
                };
                xhr.onerror = function () {
                    hiba(new Error('Hálózati hiba a feltöltés közben.'));
                };
                xhr.send(adat);
            });
        }

        return fetch(url, { method: 'POST', body: adat, credentials: 'same-origin' })
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

    /* ---------------------------------------------------------------- */
    /* Felugró üzenet és saját kis ablak                                */
    /* ---------------------------------------------------------------- */

    var toastDoboz = null;

    function toast(szoveg, tipus) {
        if (!toastDoboz) {
            toastDoboz = document.createElement('div');
            toastDoboz.className = 'sdh-pt-toastok';
            toastDoboz.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastDoboz);
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

    var BEZAR_IKON = '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M3.6 3.6l4.8 4.8M8.4 3.6l-4.8 4.8"/></svg>';

    /**
     * Kis saját ablak (ajánlat, import): ugyanaz a keret, mint az app.js
     * választóablakaié (macOS gombok bal felül).
     */
    function ablak(cim, html, osztaly) {
        var dialog = document.createElement('dialog');

        dialog.className = 'sdh-modal sdh-modal--pop sdh-pt-ablak' + (osztaly ? ' ' + osztaly : '');
        dialog.innerHTML = '<div class="sdh-modal__doboz">' +
            '<button type="button" class="sdh-modal__bezar" aria-label="Bezárás" title="Bezárás">' + BEZAR_IKON + '</button>' +
            '<div class="sdh-modal__torzs"><h2 class="sdh-modal__cim">' + e(cim) + '</h2><div data-pt-ablak-tartalom>' + html + '</div></div></div>';

        document.body.appendChild(dialog);

        dialog.querySelector('.sdh-modal__bezar').addEventListener('click', function () {
            dialog.close();
        });

        dialog.addEventListener('close', function () {
            window.setTimeout(function () { dialog.remove(); }, 0);
        });

        dialog.showModal();

        return dialog;
    }

    /* ---------------------------------------------------------------- */
    /* Pill-csoportok és a felvitel ablakának viselkedése               */
    /* ---------------------------------------------------------------- */

    function pillFrissit(csoport) {
        Array.prototype.forEach.call(csoport.querySelectorAll('.sdh-pt-pill'), function (p) {
            var r = p.querySelector('input');

            p.classList.toggle('is-aktiv', !!(r && r.checked));
        });
    }

    function pillErtek(gyoker, nev) {
        var r = gyoker.querySelector('input[name="' + nev + '"]:checked');

        return r ? r.value : '';
    }

    /** A szerveroldali űrlap (felvitel, fizetés) mezőinek ki-be kapcsolása a típus és a mód szerint. */
    function urlapAllapot(urlap) {
        var tipus = pillErtek(urlap, 'tipus') || urlap.getAttribute('data-tipus') || 'bevetel';
        var mod = pillErtek(urlap, 'mod') || 'kp';

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-pt-csak]'), function (blokk) {
            var latszik = blokk.getAttribute('data-sdh-pt-csak').split(' ').indexOf(tipus) !== -1;

            blokk.hidden = !latszik;
            // A rejtett mező ne menjen be (két „nev" mező is van: ügyfél és partner).
            Array.prototype.forEach.call(blokk.querySelectorAll('input'), function (m) {
                m.disabled = !latszik;
            });
        });

        var vegyes = tipus === 'bevetel' && mod === 'vegyes';
        var egy = urlap.querySelector('[data-sdh-pt-egy]');
        var tobb = urlap.querySelector('[data-sdh-pt-vegyes]');

        if (egy) {
            egy.hidden = vegyes;
            Array.prototype.forEach.call(egy.querySelectorAll('input'), function (m) { m.disabled = vegyes; });
        }

        if (tobb) {
            tobb.hidden = !vegyes;
            Array.prototype.forEach.call(tobb.querySelectorAll('input'), function (m) { m.disabled = !vegyes; });
        }

        var fajta = pillErtek(urlap, 'fajta');
        var cimke = urlap.querySelector('label[for$="ptf_osszeg"]');

        if (cimke && fajta) {
            cimke.firstChild.textContent = fajta === 'eloleg' ? 'Előleg összege ' : 'Kapott összeg ';
        }
    }

    var mlIdozito = null;

    function mlKeres(urlap, mezo, azonnal) {
        var info = urlap.querySelector('[data-sdh-pt-ml-info]');
        var szam = String(mezo.value || '').replace(/\D/g, '');

        window.clearTimeout(mlIdozito);

        if (szam.length < 2) {
            if (info) {
                info.textContent = '';
            }

            return;
        }

        mlIdozito = window.setTimeout(function () {
            kuld('munkalap', { szam: szam }).then(function (m) {
                if (info) {
                    info.innerHTML = e('ML ' + m.szam + ' · ' + (m.nev || 'névtelen') + ' · ' + penz(m.brutto) +
                        (m.fizetve ? ' · fizetve' : ' · fizetendő ' + penz(m.fizetendo)) +
                        (m.kasszaban > 0 ? ' · pénztárban már ' + penz(m.kasszaban) : '')) +
                        ' <button type="button" class="sdh-pt-link" data-sdh-pt-ml-kitolt>Kitöltés</button>';
                    info.sdhMunkalap = m;
                }

                mlKitolt(urlap, m, true);
            }).catch(function () {
                if (info) {
                    info.textContent = 'Ez a szám nincs a CRM munkalapjai között (régi lap is lehet) – a tétel így is menthető.';
                    info.sdhMunkalap = null;
                }
            });
        }, azonnal ? 0 : 350);
    }

    /** A munkalap adataival kitölti az üres mezőket (csakUres: a beírtat nem írja felül). */
    function mlKitolt(urlap, m, csakUres) {
        function tolt(nev, ertek) {
            var mezo = urlap.querySelector('input[name="' + nev + '"]:not([disabled])');

            if (mezo && ertek !== '' && ertek !== null && ertek !== undefined && (!csakUres || mezo.value.trim() === '')) {
                mezo.value = ertek;
            }
        }

        tolt('leiras', m.leiras);
        tolt('nev', m.nev);
        tolt('szamla', m.szamla);

        var osszeg = m.fizetve ? Math.max(0, m.brutto - m.kasszaban) : Math.max(0, m.fizetendo);

        if (osszeg > 0) {
            var modR = urlap.querySelector('input[name="mod"][value="' + (m.mod || 'kp') + '"]');

            if (modR && (!csakUres || !urlap.querySelector('input[name="osszeg"]') || urlap.querySelector('input[name="osszeg"]').value.trim() === '')) {
                modR.checked = true;
                pillFrissit(modR.closest('[data-sdh-pt-pillek]'));
                urlapAllapot(urlap);
            }

            tolt('osszeg', String(Math.round(osszeg)));
        }
    }

    document.addEventListener('sdh:urlap-betoltve', function (esemeny) {
        var d = esemeny.detail || {};

        if (d.modul !== 'penztar' && d.modul !== 'penztarfizetes') {
            return;
        }

        var urlap = d.torzs.querySelector('[data-sdh-pt-urlap]');

        if (!urlap) {
            return;
        }

        urlapAllapot(urlap);

        var leiras = urlap.querySelector('input[name="leiras"]:not([disabled])') || urlap.querySelector('input[name="osszeg"]');

        if (leiras) {
            window.setTimeout(function () { leiras.focus(); }, 30);
        }

        var ml = urlap.querySelector('[data-sdh-pt-ml]');

        if (ml && ml.value) {
            mlKeres(urlap, ml, true);
        }
    });

    document.addEventListener('change', function (esemeny) {
        var csoport = esemeny.target.closest && esemeny.target.closest('[data-sdh-pt-pillek]');

        if (!csoport) {
            return;
        }

        pillFrissit(csoport);

        var urlap = csoport.closest('[data-sdh-pt-urlap]');

        if (urlap) {
            urlapAllapot(urlap);
        }
    });

    document.addEventListener('input', function (esemeny) {
        if (esemeny.target.matches && esemeny.target.matches('[data-sdh-pt-ml]')) {
            mlKeres(esemeny.target.closest('form'), esemeny.target, false);
        }
    });

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;

        var partner = cel.closest('[data-sdh-pt-partner]');

        if (partner) {
            var pm = partner.closest('form').querySelector('#pt_partner, [id$="-pt_partner"]');

            if (pm) {
                pm.value = partner.getAttribute('data-sdh-pt-partner');
                var lm = partner.closest('form').querySelector('input[name="leiras"]');

                if (lm && lm.value.trim() === '') {
                    lm.value = pm.value;
                }
            }

            return;
        }

        var kitolt = cel.closest('[data-sdh-pt-ml-kitolt]');

        if (kitolt) {
            var info = kitolt.closest('[data-sdh-pt-ml-info]');

            if (info && info.sdhMunkalap) {
                mlKitolt(kitolt.closest('form'), info.sdhMunkalap, false);
            }

            return;
        }

        var torol = cel.closest('[data-sdh-pt-torol]');

        if (torol) {
            esemeny.preventDefault();

            if (!torol.classList.contains('is-biztos')) {
                torol.classList.add('is-biztos');
                torol.textContent = 'Biztosan törlöd?';

                return;
            }

            torol.disabled = true;
            kuld('torol', { id: torol.getAttribute('data-sdh-pt-torol') }).then(function () {
                var n = app().sajatSzint ? app().sajatSzint(torol) : 0;

                app().bezar(n < 0 ? 0 : n);
                toast('A tétel törölve (a változásnaplóban megmarad).');
                document.dispatchEvent(new CustomEvent('sdh:penztar-valtozott'));
            }).catch(function (h) {
                torol.disabled = false;
                toast(h.message, 'hiba');
            });

            return;
        }

        var fizetes = cel.closest('[data-sdh-penztar-fizetes]');

        if (fizetes) {
            esemeny.preventDefault();
            fizetesIndit(fizetes);
        }
    });

    /* ---------------------------------------------------------------- */
    /* 1. Munkalap: Fizetés gomb, ajánlat mentés és számla után          */
    /* ---------------------------------------------------------------- */

    /** A munkalap láblécének Fizetés gombja: mentés → újratöltés → fizetés ablaka fölötte. */
    function fizetesIndit(gomb) {
        var urlap = gomb.closest('form');
        var azon = urlap ? urlap.querySelector('input[name="id"]') : null;

        if (!urlap || !azon || !(parseInt(azon.value, 10) > 0)) {
            return;
        }

        if (urlap.reportValidity && !urlap.reportValidity()) {
            return;
        }

        var id = azon.value;
        var n = app().sajatSzint ? Math.max(0, app().sajatSzint(gomb)) : 0;
        var felirat = gomb.textContent;
        var adat = new FormData(urlap);

        gomb.disabled = true;
        gomb.textContent = 'Mentés…';
        adat.set('action', urlap.dataset.sdhAjaxAction);

        fetch(B.ajax, { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.uzenet) || 'A munkalap mentése nem sikerült.');
                }

                document.dispatchEvent(new CustomEvent('sdh:mentve', {
                    cancelable: true,
                    detail: { action: urlap.dataset.sdhAjaxAction || '', adat: json.data || {}, forras: 'penztar' }
                }));

                app().nyit('munkalapok', id, { szint: n });
                app().nyit('penztarfizetes', id, {
                    szint: Math.min(n + 1, 3),
                    siker: function (valasz) {
                        toast((valasz && valasz.uzenet) || 'Fizetés rögzítve.', 'siker');
                        app().nyit('munkalapok', id, { szint: n });
                        document.dispatchEvent(new CustomEvent('sdh:penztar-valtozott'));
                    }
                });
            })
            .catch(function (h) {
                gomb.disabled = false;
                gomb.textContent = felirat;

                if (app().hiba) {
                    app().hiba(urlap, 'A fizetés előtt a munkalapot el kell menteni. ' + h.message);
                }
            });
    }

    /**
     * „Bekerüljön a házipénztárba?" – a munkalap adataival kitöltve, egy
     * kattintással beírható; „Nem kell" esetén nyoma marad (az egyeztetéshez).
     */
    function ajanlatAblak(a, cim, utana) {
        var modok = { kp: 'Készpénz', kartya: 'Bankkártya', utalas: 'Utalás' };
        var html =
            '<p class="sdh-pt-ajanlat__fo">' + e((a.szam ? 'ML ' + a.szam + ' · ' : '') + (a.nev || 'névtelen ügyfél')) +
            '<strong>' + e(penz(a.osszeg)) + '</strong></p>' +
            '<p class="sdh-pt-ajanlat__al">' + e(a.eloleg_mod ? 'Előleg érkezett a munkalapra.' : (a.szamla ? 'Számla: ' + a.szamla : 'A munkalap most lett fizetve.')) + '</p>' +
            '<form class="sdh-pt-ajanlat" data-sdh-pt-urlap data-tipus="bevetel" novalidate>' +
            '<div class="sdh-pt-pillek" data-sdh-pt-pillek="mod">' +
            Object.keys(modok).map(function (k) {
                return '<label class="sdh-pt-pill' + (k === (a.mod || 'kp') ? ' is-aktiv' : '') + '"><input type="radio" name="mod" value="' + k + '"' + (k === (a.mod || 'kp') ? ' checked' : '') + '><span>' + modok[k] + '</span></label>';
            }).join('') + '</div>' +
            '<div class="sdh-pt-ajanlat__mezok">' +
            '<label>Összeg<span class="sdh-szam sdh-szam--utotag"><input type="text" inputmode="decimal" name="osszeg" value="' + e(Math.round(a.osszeg)) + '"><span class="sdh-szam__utotag">Ft</span></span></label>' +
            '<label>Név<input type="text" name="nev" maxlength="190" value="' + e(a.nev || '') + '"></label>' +
            '<label class="sdh-pt-ajanlat__szeles">Mi történt<input type="text" name="leiras" maxlength="255" value="' + e((a.eloleg_mod ? 'Előleg – ' : '') + (a.leiras || '')) + '"></label>' +
            '<label>Számlaszám<input type="text" name="szamla" maxlength="60" value="' + e(a.szamla || '') + '"></label>' +
            '</div>' +
            '<div class="sdh-urlap__lablec">' +
            '<button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Beírás a pénztárba</button>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-pt-kihagy>Nem kell</button>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-pt-kesobb>Később</button>' +
            '</div></form>';

        var d = ablak(cim || 'Bekerüljön a házipénztárba?', html, 'sdh-pt-ablak--ajanlat');
        var urlap = d.querySelector('form');
        var kesz = false;

        d.addEventListener('close', function () {
            if (typeof utana === 'function') {
                utana(kesz);
            }
        });

        urlap.addEventListener('submit', function (ev) {
            ev.preventDefault();

            var mod = pillErtek(urlap, 'mod') || 'kp';
            var osszeg = urlap.querySelector('[name="osszeg"]').value;
            var gomb = urlap.querySelector('button[type="submit"]');

            gomb.disabled = true;

            kuld('gyors', {
                tipus: 'bevetel',
                leiras: urlap.querySelector('[name="leiras"]').value,
                nev: urlap.querySelector('[name="nev"]').value,
                kp: mod === 'kp' ? osszeg : '0',
                kartya: mod === 'kartya' ? osszeg : '0',
                utalas: mod === 'utalas' ? osszeg : '0',
                ml: a.szam || '',
                szamla: urlap.querySelector('[name="szamla"]').value,
                megerositve: '1'
            }).then(function () {
                kesz = true;
                toast('Beírva a pénztárba: ' + penz(osszeg) + '.', 'siker');
                document.dispatchEvent(new CustomEvent('sdh:penztar-valtozott'));
                d.close();
            }).catch(function (h) {
                gomb.disabled = false;
                toast(h.message, 'hiba');
            });
        });

        urlap.querySelector('[data-pt-kihagy]').addEventListener('click', function () {
            kuld('kihagy', { munkalap_id: a.id, osszeg: urlap.querySelector('[name="osszeg"]').value }).then(function () {
                kesz = true;
                toast('Rendben, nem került a kasszába (az egyeztetéshez nyoma maradt).');
                d.close();
            }).catch(function (h) { toast(h.message, 'hiba'); });
        });

        urlap.querySelector('[data-pt-kesobb]').addEventListener('click', function () {
            d.close();
        });

        urlap.querySelector('[name="osszeg"]').focus();
    }

    // Munkalap mentése után: ha most lett fizetve / nőtt az előleg, ajánlat.
    document.addEventListener('sdh:mentve', function (esemeny) {
        var d = esemeny.detail || {};

        if (d.forras || !/_munkalapok_ment$/.test(d.action || '') || !d.adat || !d.adat.penztar) {
            return;
        }

        var vissza = d.adat.vissza;
        var maradunk = esemeny.defaultPrevented;

        // Ha senki nem fogta el (lista oldal), az átirányítás az ajánlat után jön.
        if (!maradunk) {
            esemeny.preventDefault();
        }

        window.setTimeout(function () {
            ajanlatAblak(d.adat.penztar, null, function () {
                if (!maradunk && vissza) {
                    window.location.href = vissza;
                }
            });
        }, 120);
    });

    // Számla után: a szám már beíródott (szerver), vagy ajánlat, ha a pénz még nincs a pénztárban.
    document.addEventListener('sdh:szamla-kesz', function (esemeny) {
        var d = esemeny.detail || {};

        if (!d.munkalapId || d.sorozat === 'helyi') {
            return;
        }

        kuld('ajanlat', { munkalap_id: d.munkalapId }).then(function (v) {
            if (v.beirva) {
                toast('A számlaszám (' + v.beirva + ') a pénztártételbe is beíródott.', 'siker');
            } else if (v.ajanlat) {
                window.setTimeout(function () {
                    ajanlatAblak(v.ajanlat, 'Számla kiállítva – bekerüljön a pénztárba?');
                }, 400);
            }
        }).catch(function () { /* a számla elkészült; a pénztár később is pótolható */ });
    });

    /* ================================================================ */
    /* 2. A Pénztár oldal                                               */
    /* ================================================================ */

    var gyoker = document.querySelector('[data-sdh-penztar]');

    if (!gyoker) {
        return;
    }

    var indulo = {};

    try {
        indulo = JSON.parse(gyoker.getAttribute('data-indulo') || '{}');
    } catch (x) {
        indulo = {};
    }

    var S = {
        ma: indulo.ma,
        lap: 'ma',
        csomag: indulo.csomag,
        lezaratlan: indulo.lezaratlan || [],
        szemelyek: indulo.szemelyek || [],
        cimletek: indulo.cimletek || [],
        ai: !!indulo.ai,
        naplo: { oldal: 1, szuro: '', adat: null, kiemel: 0 },
        kp: { datum: indulo.ma, csomag: null, db: {} },
        riport: { elore: 'ho', tol: '', ig: '' },
        stat: { elore: 'ev', tol: '', ig: '', bontas: '' },
        ugynok: { datum: indulo.ma },
        kereses: { q: '', oldal: 1, szuro: {} }
    };

    var munkalapUrl = gyoker.getAttribute('data-munkalap-url') || '';

    function tarol(k, v) {
        try {
            if (v === undefined) {
                return window.localStorage.getItem(k);
            }

            window.localStorage.setItem(k, v);
        } catch (x) { /* privát mód */ }

        return null;
    }

    var IKON = {
        kereso: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.2"/><path d="m12.4 12.4 4.3 4.3"/></svg>',
        balra: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5"/></svg>',
        jobbra: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7.5 4.5 13 10l-5.5 5.5"/></svg>',
        hiba: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.2"/><path d="M10 6.2v4.6M10 13.6v.2"/></svg>',
        figyelem: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3.2 17.4 16H2.6z"/><path d="M10 8v3.6M10 13.8v.2"/></svg>',
        info: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.2"/><path d="M10 9v5M10 6.3v.2"/></svg>',
        pipa: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m4.5 10.5 3.5 3.5 7.5-8"/></svg>',
        ugynok: '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="4" y="6" width="12" height="9" rx="2.5"/><path d="M10 6V3.5M7.5 10.2v.4M12.5 10.2v.4M2 10.5v2M18 10.5v2"/></svg>'
    };

    var LAPOK = [
        ['ma', 'Ma'],
        ['naplo', 'Napló'],
        ['kp', 'KP – kassza'],
        ['riport', 'Riport'],
        ['statisztika', 'Statisztika'],
        ['ugynok', 'Ügynök'],
        ['kereses', 'Keresés']
    ];

    gyoker.innerHTML =
        '<header class="sdh-pt__fej">' +
        '  <div class="sdh-pt__cim"><p class="sdh-fejlec__kalap">SDH Műhely</p><h1>Házipénztár</h1><p class="sdh-pt__alcim" data-pt-alcim></p></div>' +
        '  <form class="sdh-pt__kereso" data-pt-kereso role="search">' + IKON.kereso +
        '    <input type="search" name="q" autocomplete="off" placeholder="Számlaszám, munkalap sorszám, név, összeg…" aria-label="Keresés a pénztárban">' +
        '  </form>' +
        '  <div class="sdh-pt__gombok">' +
        '    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-pt-uj="bevetel"><span class="sdh-plusz" aria-hidden="true"></span>Bevétel</button>' +
        '    <button type="button" class="sdh-gomb" data-pt-uj="kivet">Kivét</button>' +
        '    <button type="button" class="sdh-gomb" data-pt-uj="kifizetes">Kifizetés</button>' +
        '    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-pt-import>Import</button>' +
        '  </div>' +
        '</header>' +
        '<div class="sdh-pt__figy" data-pt-figy hidden></div>' +
        '<nav class="sdh-pt__fulek" role="tablist">' +
        LAPOK.map(function (l) {
            return '<button type="button" role="tab" class="sdh-pt__ful" data-pt-ful="' + l[0] + '"' + (l[0] === 'kereses' ? ' hidden' : '') + '>' + l[1] + '</button>';
        }).join('') +
        '</nav>' +
        LAPOK.map(function (l) {
            return '<section class="sdh-pt__lap" data-pt-lap="' + l[0] + '" role="tabpanel" hidden></section>';
        }).join('');

    function lapElem(nev) {
        return gyoker.querySelector('[data-pt-lap="' + nev + '"]');
    }

    var betoltve = {};

    function lapra(nev, adat) {
        if (!lapElem(nev)) {
            nev = 'ma';
        }

        S.lap = nev;

        if (nev !== 'kereses') {
            tarol('sdh-pt-lap', nev);
        }

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-pt-ful]'), function (f) {
            var aktiv = f.getAttribute('data-pt-ful') === nev;

            f.classList.toggle('is-aktiv', aktiv);
            f.setAttribute('aria-selected', aktiv ? 'true' : 'false');

            if (f.getAttribute('data-pt-ful') === 'kereses' && aktiv) {
                f.hidden = false;
            }
        });

        Array.prototype.forEach.call(gyoker.querySelectorAll('[data-pt-lap]'), function (l) {
            l.hidden = l.getAttribute('data-pt-lap') !== nev;
        });

        var rajzolok = { ma: maRajzol, naplo: naploTolt, kp: kpTolt, riport: riportTolt, statisztika: statTolt, ugynok: ugynokTolt, kereses: keresesTolt };

        rajzolok[nev](adat);
        betoltve[nev] = true;
    }

    function alcimFrissit() {
        var n = S.csomag && S.csomag.nap;
        var hely = gyoker.querySelector('[data-pt-alcim]');

        if (!hely || !n) {
            return;
        }

        hely.innerHTML = e(datumHosszu(n.datum)) + ' · ' + allapotJel(n) + ' · várható KP a kasszában: <strong>' + e(penz(n.zaro)) + '</strong>';

        var figy = gyoker.querySelector('[data-pt-figy]');
        var lista = (S.lezaratlan || []).filter(function (d) { return d !== S.ma; });

        figy.hidden = lista.length === 0;
        figy.innerHTML = lista.length ? IKON.figyelem + '<span>' + (lista.length === 1
            ? 'A ' + e(datumHosszu(lista[0])) + ' nap nincs lezárva.'
            : lista.length + ' korábbi nap nincs lezárva (legutóbb: ' + e(datumHosszu(lista[0])) + ').') + '</span>' +
            '<button type="button" class="sdh-gomb sdh-gomb--kicsi" data-pt-kp-nap="' + e(lista[0]) + '">Megszámolom és lezárom</button>' : '';
    }

    function allapotJel(n) {
        if (n.allapot === 'lezart') {
            return '<span class="sdh-pt-jel sdh-pt-jel--lezart">' + IKON.pipa + 'Lezárva</span>';
        }

        return '<span class="sdh-pt-jel sdh-pt-jel--nyitott">Nyitott</span>';
    }

    function elteresJel(n) {
        if (n.elteres === null || n.elteres === undefined) {
            return n.allapot === 'lezart' && !n.import ? '<span class="sdh-pt-jel">Nem számolt</span>' : '';
        }

        if (Math.abs(n.elteres) < 1) {
            return '<span class="sdh-pt-jel sdh-pt-jel--ok">' + IKON.pipa + 'Egyezik</span>';
        }

        return '<span class="sdh-pt-jel sdh-pt-jel--' + (n.elteres > 0 ? 'tobb' : 'hiany') + '">' + (n.elteres > 0 ? IKON.info : IKON.figyelem) +
            (n.elteres > 0 ? 'Többlet ' : 'Hiány ') + e(penz(Math.abs(n.elteres))) + '</span>';
    }

    /* ---------------- Tételtáblázat (Ma, Napló) ---------------- */

    function tetelSor(t, opciok) {
        opciok = opciok || {};

        var kiadas = t.tipus === 'kivet' || t.tipus === 'kifizetes' || t.tipus === 'befizetes';
        var kihagyva = t.tipus === 'kihagyva';
        var cimke = '';

        if (t.tipus === 'kivet') {
            cimke = '<span class="sdh-pt-cimke sdh-pt-cimke--kivet">Kivét · ' + e(t.szemely || '?') + '</span>';
        } else if (t.tipus === 'kifizetes') {
            cimke = '<span class="sdh-pt-cimke sdh-pt-cimke--kifizetes">Kifizetés</span>';
        } else if (t.tipus === 'befizetes') {
            cimke = '<span class="sdh-pt-cimke">Befizetés</span>';
        } else if (kihagyva) {
            cimke = '<span class="sdh-pt-cimke sdh-pt-cimke--kihagyva">Nem került kasszába · ' + e(penz(t.info)) + '</span>';
        }

        var ml = t.ml ? (opciok.mlCrm && opciok.mlCrm[t.ml]
            ? '<button type="button" class="sdh-pt-link" data-pt-munkalap="' + opciok.mlCrm[t.ml] + '">' + e(t.ml) + '</button>'
            : e(t.ml)) : '';

        return '<tr class="sdh-pt-sor sdh-pt-sor--' + e(t.tipus) + (opciok.kiemel === t.id ? ' is-kiemelt' : '') + '" data-pt-tetel="' + t.id + '" tabindex="0">' +
            (opciok.datum ? '<td class="sdh-pt-c-datum"><button type="button" class="sdh-pt-link" data-pt-naplo-ugras="' + e(t.datum) + '" data-pt-tetel-id="' + t.id + '">' + e(t.datum) + '</button></td>' : '') +
            (opciok.ido ? '<td class="sdh-pt-c-ido">' + e(t.ido) + '</td>' : '') +
            '<td class="sdh-pt-c-leiras"><span class="sdh-pt-leiras">' + kiemel(t.leiras, opciok.q) + '</span>' +
            (t.nev && t.tipus !== 'kivet' ? '<span class="sdh-pt-nev">' + kiemel(t.nev, opciok.q) + '</span>' : '') + cimke +
            (t.megj ? '<span class="sdh-pt-megj">' + kiemel(t.megj, opciok.q) + '</span>' : '') + '</td>' +
            '<td class="sdh-pt-c-szam sdh-pt-c-kp">' + (kiadas || kihagyva ? '' : e(szam0(t.kp))) + '</td>' +
            '<td class="sdh-pt-c-szam sdh-pt-c-kartya">' + e(szam0(t.kartya)) + '</td>' +
            '<td class="sdh-pt-c-szam sdh-pt-c-utalas">' + e(szam0(t.utalas)) + '</td>' +
            '<td class="sdh-pt-c-szam sdh-pt-c-ki">' + (kiadas ? e(szam0(t.kp)) : '') + '</td>' +
            '<td class="sdh-pt-c-ml">' + ml + '</td>' +
            '<td class="sdh-pt-c-szamla">' + kiemel(t.szamla, opciok.q) + '</td>' +
            '<td class="sdh-pt-c-vonal">' + kiemel(t.vonalkod, opciok.q) + '</td>' +
            '</tr>';
    }

    function szam0(n) {
        return Math.round(Number(n) || 0) === 0 ? '' : szam(n);
    }

    function kiemel(szoveg, q) {
        var s = e(szoveg || '');

        if (!q || !szoveg) {
            return s;
        }

        var szavak = String(q).trim().split(/\s+/).filter(function (x) { return x.length > 1; });

        szavak.forEach(function (sz) {
            var minta = e(sz).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

            s = s.replace(new RegExp('(' + minta + ')(?![^<]*>)', 'gi'), '<mark>$1</mark>');
        });

        return s;
    }

    function tablaFej(opciok) {
        opciok = opciok || {};

        return '<thead><tr>' +
            (opciok.datum ? '<th class="sdh-pt-c-datum">Dátum</th>' : '') +
            (opciok.ido ? '<th class="sdh-pt-c-ido">Idő</th>' : '') +
            '<th class="sdh-pt-c-leiras">Cikkszám / munka</th>' +
            '<th class="sdh-pt-c-szam">KP</th><th class="sdh-pt-c-szam">B.kártya</th><th class="sdh-pt-c-szam sdh-pt-c-utalas">Utalás</th>' +
            '<th class="sdh-pt-c-szam">Kifizetés</th><th class="sdh-pt-c-ml">Munkalap</th><th class="sdh-pt-c-szamla">Számlaszám</th><th class="sdh-pt-c-vonal">Vonalkód</th>' +
            '</tr></thead>';
    }

    function tablaLab(n, ido) {
        return '<tfoot><tr>' +
            '<th class="sdh-pt-c-leiras"' + (ido ? ' colspan="2"' : '') + '>Napi összesen · ' + n.db + ' tétel</th>' +
            '<td class="sdh-pt-c-szam">' + e(szam0(n.kp_be)) + '</td>' +
            '<td class="sdh-pt-c-szam">' + e(szam0(n.kartya)) + '</td>' +
            '<td class="sdh-pt-c-szam sdh-pt-c-utalas">' + e(szam0(n.utalas)) + '</td>' +
            '<td class="sdh-pt-c-szam">' + e(szam0(n.kifizetes + n.kivet + n.befizetes)) + '</td>' +
            '<td colspan="3" class="sdh-pt-lab-osszeg">' +
            '<span>KP a kasszában <strong>' + e(penz(n.szamolt !== null ? n.szamolt : n.zaro)) + '</strong></span>' +
            '<span>KP+BK <strong>' + e(penz(n.forgalom)) + '</strong></span>' +
            '<span>Össz. forg. <strong>' + e(penz(n.halmozott)) + '</strong></span>' +
            '</td></tr></tfoot>';
    }

    /* ---------------- Ma ---------------- */

    function maRajzol(csomag) {
        if (csomag) {
            S.csomag = csomag;
        }

        var c = S.csomag || { nap: {}, tetelek: [] };
        var n = c.nap;
        var hely = lapElem('ma');
        var utalasVan = c.tetelek.some(function (t) { return Math.round(t.utalas) !== 0; });

        S.ma = n.datum || S.ma;
        alcimFrissit();

        hely.innerHTML =
            '<div class="sdh-pt-kpik">' +
            kpi('Nyitó KP', penz(n.nyito), 'Az előző nap záró készpénze') +
            kpi('KP bevétel', penz(n.kp_be), '', 'kp') +
            kpi('Bankkártya', penz(n.kartya), '', 'kartya') +
            (utalasVan ? kpi('Utalás', penz(n.utalas), '', 'utalas') : '') +
            kpi('Kivét + kifizetés', penz(n.kivet + n.kifizetes), n.kivet ? 'ebből kivét ' + penz(n.kivet) : '') +
            kpi('KP a kasszában', penz(n.zaro), n.szamolt !== null ? 'megszámolt: ' + penz(n.szamolt) : 'várható – a KP lapon számold meg', 'fo') +
            kpi('Napi forgalom', penz(n.forgalom), 'KP + kártya + utalás') +
            '</div>' +
            (n.allapot === 'lezart'
                ? '<div class="sdh-pt-savszoveg sdh-pt-savszoveg--lezart">' + IKON.pipa + '<span>A nap lezárva ' + e((n.lezarva || '').slice(11, 16)) +
                  (n.lezarta ? ' · ' + e(n.lezarta) : '') + ' · ' + elteresJel(n) + '</span>' +
                  '<button type="button" class="sdh-gomb sdh-gomb--kicsi sdh-gomb--vilagos" data-pt-ujranyit="' + e(n.datum) + '">Újranyitás</button></div>'
                : '') +
            '<form class="sdh-pt-gyors" data-pt-gyors autocomplete="off">' +
            '  <input type="text" name="leiras" placeholder="Mi történt? (Enter = rögzítés)" aria-label="Mi történt" required>' +
            '  <input type="text" name="nev" placeholder="Név" aria-label="Ügyfél neve">' +
            '  <span class="sdh-pt-gyors__szam"><input type="text" name="kp" inputmode="decimal" placeholder="KP" aria-label="Készpénz"></span>' +
            '  <span class="sdh-pt-gyors__szam"><input type="text" name="kartya" inputmode="decimal" placeholder="Kártya" aria-label="Bankkártya"></span>' +
            '  <input type="text" name="ml" inputmode="numeric" placeholder="Munkalap" aria-label="Munkalap sorszám" data-pt-gyors-ml>' +
            '  <input type="text" name="szamla" placeholder="Számlaszám" aria-label="Számlaszám">' +
            '  <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Rögzítés</button>' +
            '  <p class="sdh-pt-gyors__info" data-pt-gyors-info></p>' +
            '</form>' +
            '<div class="sdh-pt-tabla-keret"><table class="sdh-pt-tabla' + (utalasVan ? ' van-utalas' : '') + '">' + tablaFej({ ido: true }) +
            '<tbody>' + (c.tetelek.length ? c.tetelek.map(function (t) { return tetelSor(t, { ido: true }); }).join('')
                : '<tr class="sdh-pt-ures"><td colspan="9">Ma még nincs tétel. Írd be fent, vagy fizess egy munkalapot – a rendszer felajánlja a beírást.</td></tr>') +
            '</tbody>' + tablaLab(n, true) + '</table></div>' +
            '<div class="sdh-pt-lablec">' +
            '<button type="button" class="sdh-pt-link" data-pt-valtozasok="' + e(n.datum) + '">Változásnapló' + (c.torolt ? ' (' + c.torolt + ' törölt tétel)' : '') + '</button>' +
            '<button type="button" class="sdh-pt-link" data-pt-kp-nap="' + e(n.datum) + '">Kassza megszámolása és zárás →</button>' +
            '</div>';

        var elso = hely.querySelector('[data-pt-gyors] [name="leiras"]');

        if (elso && document.activeElement === document.body) {
            elso.focus();
        }
    }

    function kpi(cim, ertek, al, fajta) {
        return '<div class="sdh-pt-kpi' + (fajta ? ' sdh-pt-kpi--' + fajta : '') + '"><span class="sdh-pt-kpi__cim">' + e(cim) + '</span>' +
            '<strong class="sdh-pt-kpi__ertek">' + e(ertek) + '</strong>' + (al ? '<span class="sdh-pt-kpi__al">' + e(al) + '</span>' : '') + '</div>';
    }

    function maFrissit() {
        return kuld('adat', {}).then(function (c) {
            S.lezaratlan = c.lezaratlan || S.lezaratlan;

            if (S.lap === 'ma') {
                maRajzol(c);
            } else {
                S.csomag = c;
                alcimFrissit();
            }
        });
    }

    gyoker.addEventListener('submit', function (esemeny) {
        var urlap = esemeny.target;

        if (urlap.matches('[data-pt-gyors]')) {
            esemeny.preventDefault();
            gyorsMent(urlap, false);
        } else if (urlap.matches('[data-pt-kereso]')) {
            esemeny.preventDefault();
            S.kereses.q = urlap.querySelector('input').value.trim();
            S.kereses.oldal = 1;
            lapra('kereses');
        }
    });

    function gyorsMent(urlap, megerositve) {
        var m = {};

        Array.prototype.forEach.call(urlap.querySelectorAll('input'), function (i) { m[i.name] = i.value; });
        m.tipus = 'bevetel';

        if (megerositve) {
            m.megerositve = '1';
        }

        var gomb = urlap.querySelector('button[type="submit"]');

        gomb.disabled = true;

        kuld('gyors', m).then(function (c) {
            gomb.disabled = false;
            urlap.reset();
            urlap.querySelector('[data-pt-gyors-info]').textContent = '';
            maRajzol(c);
            toast('Rögzítve.', 'siker');
            var sor = gyoker.querySelector('[data-pt-tetel="' + c.id + '"]');

            if (sor) {
                sor.classList.add('is-uj');
            }
        }).catch(function (h) {
            gomb.disabled = false;

            if (h.adat && h.adat.dupla) {
                var info = urlap.querySelector('[data-pt-gyors-info]');

                info.innerHTML = IKON.figyelem + e(h.message) + ' <button type="button" class="sdh-pt-link" data-pt-gyors-megis>Igen, beírom</button>';

                return;
            }

            toast(h.message, 'hiba');
        });
    }

    // A gyorssor munkalapszámából kitöltés: név, leírás, összeg a fizetés módja szerint.
    var gyorsMlIdo = null;

    gyoker.addEventListener('input', function (esemeny) {
        var mezo = esemeny.target;

        if (mezo.matches('[data-pt-gyors-ml]')) {
            var urlap = mezo.closest('form');
            var info = urlap.querySelector('[data-pt-gyors-info]');
            var sz = mezo.value.replace(/\D/g, '');

            window.clearTimeout(gyorsMlIdo);

            if (sz.length < 2) {
                info.textContent = '';

                return;
            }

            gyorsMlIdo = window.setTimeout(function () {
                kuld('munkalap', { szam: sz }).then(function (ml) {
                    var osszeg = ml.fizetve ? Math.max(0, ml.brutto - ml.kasszaban) : ml.fizetendo;

                    ['leiras', 'nev', 'szamla'].forEach(function (k) {
                        var mm = urlap.querySelector('[name="' + k + '"]');

                        if (mm && !mm.value.trim() && ml[k === 'szamla' ? 'szamla' : k]) {
                            mm.value = ml[k === 'szamla' ? 'szamla' : k];
                        }
                    });

                    var cel = urlap.querySelector('[name="' + (ml.mod === 'kartya' ? 'kartya' : 'kp') + '"]');

                    if (cel && osszeg > 0 && !urlap.querySelector('[name="kp"]').value && !urlap.querySelector('[name="kartya"]').value) {
                        cel.value = Math.round(osszeg);
                    }

                    info.innerHTML = IKON.info + e('ML ' + ml.szam + ' · ' + (ml.nev || 'névtelen') + ' · ' + penz(ml.brutto) +
                        (ml.fizetesi_mod ? ' · ' + ml.fizetesi_mod : '') + (ml.kasszaban > 0 ? ' · pénztárban már: ' + penz(ml.kasszaban) : ''));
                }).catch(function () {
                    info.textContent = 'Nincs ilyen munkalap a CRM-ben – a tétel így is beírható.';
                });
            }, 300);
        }
    });

    /* ---------------- Napló ---------------- */

    function naploTolt(adat) {
        var hely = lapElem('naplo');

        if (adat && adat.ugras) {
            S.naplo.ugras = adat.ugras;
            S.naplo.kiemel = adat.kiemel || 0;
        }

        if (!hely.firstChild) {
            hely.innerHTML = '<div class="sdh-pt-eszkozsor">' +
                '<div class="sdh-pt-lapozo" data-pt-naplo-lapozo></div>' +
                '<label class="sdh-pt-ugras">Ugrás napra <input type="text" class="sdh-pop sdh-pop--datum" readonly data-sdh-pop="datum" data-sdh-pop-adat="[]" data-pt-naplo-datum placeholder="éééé-hh-nn"></label>' +
                '<div class="sdh-pt-pillek" data-pt-naplo-szuro>' +
                [['', 'Minden nap'], ['elteres', 'Csak eltérés'], ['nyitott', 'Lezáratlan']].map(function (p) {
                    return '<button type="button" class="sdh-pt-pill' + (S.naplo.szuro === p[0] ? ' is-aktiv' : '') + '" data-pt-szuro="' + p[0] + '">' + p[1] + '</button>';
                }).join('') + '</div>' +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--kicsi" data-pt-export-naplo>Excel (CSV)</button>' +
                '</div><div data-pt-naplo-napok class="sdh-pt-napok"><div class="sdh-pt-toltes">Betöltés…</div></div>';
        }

        var m = { oldal: S.naplo.oldal, szuro: S.naplo.szuro };

        if (S.naplo.ugras) {
            m.ugras = S.naplo.ugras;
        }

        kuld('naplo', m).then(function (v) {
            S.naplo.adat = v;
            S.naplo.oldal = v.oldal;
            naploRajzol(v);

            if (S.naplo.ugras) {
                var cel = S.naplo.kiemel
                    ? hely.querySelector('[data-pt-tetel="' + S.naplo.kiemel + '"]')
                    : hely.querySelector('[data-pt-nap="' + S.naplo.ugras + '"]');

                if (cel) {
                    cel.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }

                S.naplo.ugras = '';
            }
        }).catch(function (h) {
            hely.querySelector('[data-pt-naplo-napok]').innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
        });
    }

    function lapozo(oldal, oldalak, adat, info) {
        return '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--ikon" data-pt-lapoz="' + adat + '" data-irany="-1"' + (oldal <= 1 ? ' disabled' : '') + ' aria-label="Újabb">' + IKON.balra + '</button>' +
            '<span class="sdh-pt-lapozo__szoveg">' + oldal + ' / ' + oldalak + (info ? ' · ' + e(info) : '') + '</span>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--ikon" data-pt-lapoz="' + adat + '" data-irany="1"' + (oldal >= oldalak ? ' disabled' : '') + ' aria-label="Régebbi">' + IKON.jobbra + '</button>';
    }

    function naploRajzol(v) {
        var hely = lapElem('naplo');

        hely.querySelector('[data-pt-naplo-lapozo]').innerHTML = lapozo(v.oldal, v.oldalak, 'naplo', v.osszes + ' nap');

        Array.prototype.forEach.call(hely.querySelectorAll('[data-pt-szuro]'), function (p) {
            p.classList.toggle('is-aktiv', p.getAttribute('data-pt-szuro') === S.naplo.szuro);
        });

        var napok = hely.querySelector('[data-pt-naplo-napok]');

        if (!v.napok.length) {
            napok.innerHTML = '<div class="sdh-pt-ures-doboz">' + (S.naplo.szuro ? 'Nincs ilyen nap.' : 'Még nincs pénztáradat. Az <strong>Import</strong> gombbal betöltheted a régi táblát.') + '</div>';

            return;
        }

        napok.innerHTML = v.napok.map(function (x) {
            var n = x.nap;
            var utalas = x.tetelek.some(function (t) { return Math.round(t.utalas) !== 0; });

            return '<article class="sdh-pt-nap' + (n.allapot !== 'lezart' ? ' is-nyitott' : '') + '" data-pt-nap="' + e(n.datum) + '">' +
                '<header class="sdh-pt-nap__fej">' +
                '<h3><span class="sdh-pt-nap__datum">' + e(n.datum.replace(/-/g, '.')) + '.</span> <span class="sdh-pt-nap__hetnap">' + e(hetnap(n.datum)) + '</span></h3>' +
                allapotJel(n) + elteresJel(n) +
                '<span class="sdh-pt-nap__nyito">Nyitó: <strong>' + e(penz(n.nyito)) + '</strong></span>' +
                '<span class="sdh-pt-nap__gombok">' +
                '<button type="button" class="sdh-pt-link" data-pt-kp-nap="' + e(n.datum) + '">KP számolás</button>' +
                '<button type="button" class="sdh-pt-link" data-pt-egyeztet="' + e(n.datum) + '">Egyeztetés</button>' +
                '<button type="button" class="sdh-pt-link" data-pt-uj-napra="' + e(n.datum) + '">+ tétel erre a napra</button>' +
                '</span></header>' +
                '<div class="sdh-pt-tabla-keret"><table class="sdh-pt-tabla' + (utalas ? ' van-utalas' : '') + '">' + tablaFej() + '<tbody>' +
                (x.tetelek.length ? x.tetelek.map(function (t) { return tetelSor(t, { kiemel: S.naplo.kiemel }); }).join('') : '<tr class="sdh-pt-ures"><td colspan="8">Nincs tétel.</td></tr>') +
                '</tbody>' + tablaLab(n) + '</table></div>' +
                (n.megj ? '<p class="sdh-pt-nap__megj">' + e(n.megj) + '</p>' : '') +
                '</article>';
        }).join('');
    }

    /* ---------------- KP – kassza számolása és zárás ---------------- */

    function kpTolt(datum) {
        if (typeof datum === 'string' && datum) {
            S.kp.datum = datum;
        }

        var hely = lapElem('kp');

        hely.innerHTML = '<div class="sdh-pt-toltes">Betöltés…</div>';

        kuld('adat', { datum: S.kp.datum }).then(function (c) {
            S.kp.csomag = c;
            S.kp.db = {};

            Object.keys(c.nap.cimletek || {}).forEach(function (k) { S.kp.db[k] = c.nap.cimletek[k]; });

            if (c.nap.datum === S.ma) {
                S.csomag = c;
                alcimFrissit();
            }

            kpRajzol();
        }).catch(function (h) {
            hely.innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
        });
    }

    function kpOsszeg() {
        return S.cimletek.reduce(function (o, c) { return o + c * (parseInt(S.kp.db[c], 10) || 0); }, 0);
    }

    function kpRajzol() {
        var c = S.kp.csomag;
        var n = c.nap;
        var hely = lapElem('kp');
        var lezart = n.allapot === 'lezart';

        hely.innerHTML =
            '<div class="sdh-pt-kp">' +
            '<div class="sdh-pt-kp__bal">' +
            '  <div class="sdh-pt-kp__nap">' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--ikon" data-pt-kp-lep="-1" aria-label="Előző nap">' + IKON.balra + '</button>' +
            '    <input type="text" class="sdh-pop sdh-pop--datum" readonly data-sdh-pop="datum" data-sdh-pop-adat="[]" data-pt-kp-datum value="' + e(n.datum) + '" aria-label="Nap">' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--ikon" data-pt-kp-lep="1" aria-label="Következő nap"' + (n.datum >= S.ma ? ' disabled' : '') + '>' + IKON.jobbra + '</button>' +
            '    <span class="sdh-pt-kp__napnev">' + e(datumHosszu(n.datum)) + '</span>' + allapotJel(n) +
            '  </div>' +
            '  <div class="sdh-pt-cimletek" role="group" aria-label="Címletek">' +
            S.cimletek.map(function (cim) {
                var db = parseInt(S.kp.db[cim], 10) || 0;

                return '<div class="sdh-pt-cimlet' + (cim >= 500 ? ' is-papir' : ' is-erme') + '">' +
                    '<span class="sdh-pt-cimlet__nev">' + e(szam(cim)) + '</span>' +
                    '<span class="sdh-pt-cimlet__lepteto">' +
                    '<button type="button" tabindex="-1" data-pt-cimlet-lep="' + cim + '" data-irany="-1" aria-label="Eggyel kevesebb">−</button>' +
                    '<input type="text" inputmode="numeric" data-pt-cimlet="' + cim + '" value="' + (db || '') + '" placeholder="0" aria-label="' + e(szam(cim)) + ' Ft darabszáma">' +
                    '<button type="button" tabindex="-1" data-pt-cimlet-lep="' + cim + '" data-irany="1" aria-label="Eggyel több">+</button>' +
                    '</span>' +
                    '<span class="sdh-pt-cimlet__ertek" data-pt-cimlet-ertek="' + cim + '">' + e(penz(cim * db, true)) + '</span>' +
                    '</div>';
            }).join('') +
            '  </div>' +
            '  <p class="sdh-mezo__sugo">Tab / Enter: következő címlet · ↑ ↓: eggyel több / kevesebb.</p>' +
            '</div>' +
            '<div class="sdh-pt-kp__jobb">' +
            '  <div class="sdh-pt-osszevetes" data-pt-osszevetes></div>' +
            '  <label class="sdh-pt-kp__megj">Megjegyzés a zárához<textarea rows="2" data-pt-kp-megj maxlength="1000" placeholder="pl. a hiányt Peti tudja – visszahozza">' + e(n.megj || '') + '</textarea></label>' +
            '  <div class="sdh-pt-kp__gombok">' +
            '    <button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-pt-zar="1">' + (lezart ? 'Újraszámolás mentése' : 'Nap zárása') + '</button>' +
            (lezart ? '' : '    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-pt-zar="0">Csak számolás mentése</button>') +
            (lezart ? '    <button type="button" class="sdh-gomb sdh-gomb--vilagos" data-pt-ujranyit="' + e(n.datum) + '">Újranyitás</button>' : '') +
            '  </div>' +
            (lezart ? '<p class="sdh-mezo__sugo">Lezárva ' + e(n.lezarva || '') + (n.lezarta ? ' · ' + e(n.lezarta) : '') + (n.import ? ' · a régi táblából' : '') + '</p>' : '') +
            '  <div data-pt-kp-egyeztetes></div>' +
            '</div>' +
            '</div>';

        kpOsszevet();
    }

    function kpOsszevet() {
        var c = S.kp.csomag;

        if (!c) {
            return;
        }

        var n = c.nap;
        var szamolt = kpOsszeg();
        var van = Object.keys(S.kp.db).some(function (k) { return (parseInt(S.kp.db[k], 10) || 0) > 0; });
        var elteres = szamolt - n.zaro;
        var hely = lapElem('kp').querySelector('[data-pt-osszevetes]');
        var fajta = !van ? 'ures' : (Math.abs(elteres) < 1 ? 'ok' : (elteres > 0 ? 'tobb' : 'hiany'));

        hely.className = 'sdh-pt-osszevetes sdh-pt-osszevetes--' + fajta;
        hely.innerHTML =
            '<div class="sdh-pt-osszevetes__fo"><span>Megszámolt</span><strong>' + e(penz(szamolt)) + '</strong></div>' +
            '<dl class="sdh-pt-osszevetes__lanc">' +
            '<dt>Nyitó</dt><dd>' + e(penz(n.nyito)) + '</dd>' +
            '<dt>+ KP bevétel</dt><dd>' + e(penz(n.kp_be)) + '</dd>' +
            (n.befizetes ? '<dt>+ Befizetés</dt><dd>' + e(penz(n.befizetes)) + '</dd>' : '') +
            '<dt>− Kifizetés</dt><dd>' + e(penz(n.kifizetes)) + '</dd>' +
            '<dt>− Kivét</dt><dd>' + e(penz(n.kivet)) + '</dd>' +
            '<dt class="is-osszeg">Várható a kasszában</dt><dd class="is-osszeg">' + e(penz(n.zaro)) + '</dd>' +
            '</dl>' +
            '<div class="sdh-pt-osszevetes__elteres">' +
            (fajta === 'ures' ? '<span>Írd be a címletek darabszámát – az összevetés azonnal látszik.</span>'
                : fajta === 'ok' ? IKON.pipa + '<span>Egyezik – a kassza rendben van.</span>'
                    : (fajta === 'tobb' ? IKON.info : IKON.figyelem) + '<span>' + (elteres > 0 ? 'Többlet: ' : 'Hiány: ') + '<strong>' + e(penz(Math.abs(elteres))) + '</strong></span>' +
                      '<button type="button" class="sdh-pt-link" data-pt-egyeztet="' + e(n.datum) + '">Ügynök: miért?</button>') +
            '</div>';
    }

    function kpBeir(cimlet, db) {
        db = Math.max(0, parseInt(db, 10) || 0);
        S.kp.db[cimlet] = db;

        var hely = lapElem('kp');
        var mezo = hely.querySelector('[data-pt-cimlet="' + cimlet + '"]');

        if (mezo && String(mezo.value) !== String(db || '')) {
            mezo.value = db || '';
        }

        var ert = hely.querySelector('[data-pt-cimlet-ertek="' + cimlet + '"]');

        if (ert) {
            ert.textContent = penz(cimlet * db, true);
        }

        kpOsszevet();
    }

    lapElem('kp').addEventListener('input', function (esemeny) {
        var m = esemeny.target;

        if (m.matches('[data-pt-cimlet]')) {
            m.value = m.value.replace(/\D/g, '').slice(0, 5);
            kpBeir(m.getAttribute('data-pt-cimlet'), m.value);
        }
    });

    lapElem('kp').addEventListener('keydown', function (esemeny) {
        var m = esemeny.target;

        if (!m.matches('[data-pt-cimlet]')) {
            return;
        }

        if (esemeny.key === 'ArrowUp' || esemeny.key === 'ArrowDown') {
            esemeny.preventDefault();
            kpBeir(m.getAttribute('data-pt-cimlet'), (parseInt(m.value, 10) || 0) + (esemeny.key === 'ArrowUp' ? 1 : -1));
        } else if (esemeny.key === 'Enter') {
            esemeny.preventDefault();

            var mezok = Array.prototype.slice.call(lapElem('kp').querySelectorAll('[data-pt-cimlet]'));
            var kov = mezok[mezok.indexOf(m) + 1];

            if (kov) {
                kov.focus();
                kov.select();
            } else {
                lapElem('kp').querySelector('[data-pt-zar="1"]').focus();
            }
        }
    });

    function kpMent(lezar, gomb) {
        var n = S.kp.csomag.nap;
        var db = {};

        S.cimletek.forEach(function (c) { db[c] = parseInt(S.kp.db[c], 10) || 0; });

        gomb.disabled = true;

        kuld('szamolas', {
            datum: n.datum,
            cimletek: JSON.stringify(db),
            lezar: lezar ? '1' : '0',
            megj: lapElem('kp').querySelector('[data-pt-kp-megj]').value
        }).then(function (v) {
            S.lezaratlan = v.lezaratlan || S.lezaratlan;
            S.kp.csomag = v;

            if (v.nap.datum === S.ma) {
                S.csomag = v;
            }

            alcimFrissit();
            kpRajzol();

            var elteres = v.nap.elteres;

            toast(lezar ? 'A nap lezárva' + (elteres !== null && Math.abs(elteres) >= 1 ? ' – eltérés: ' + elojeles(elteres) : ' – a kassza egyezik.') : 'A számolás elmentve.', elteres !== null && Math.abs(elteres) >= 1 ? 'figyelem' : 'siker');

            if (v.egyeztetes) {
                var hely = lapElem('kp').querySelector('[data-pt-kp-egyeztetes]');

                hely.innerHTML = '<h3 class="sdh-pt-alcim2">' + IKON.ugynok + 'Az ügynök megnézte az eltérést</h3>' + egyeztetesHtml(v.egyeztetes, true);
                hely.sdhEgyeztetes = v.egyeztetes;
            }

            betoltve.naplo = false;
        }).catch(function (h) {
            gomb.disabled = false;
            toast(h.message, 'hiba');
        });
    }

    /* ---------------- Időszak-választó (Riport, Statisztika) ---------------- */

    var ELORE = [
        ['ma', 'Ma'], ['tegnap', 'Tegnap'], ['het', 'Ez a hét'], ['elozohet', 'Előző hét'], ['ho', 'Ez a hónap'],
        ['elozoho', 'Előző hónap'], ['negyedev', 'Negyedév'], ['ev', 'Idén'], ['tavaly', 'Tavaly'], ['12ho', 'Utolsó 12 hónap'], ['mind', 'Mind'], ['egyeni', 'Egyéni']
    ];

    function idoszak(kulcs, egyeniTol, egyeniIg) {
        var ma = S.ma;
        var o = datumObj(ma);
        var ev = o.getUTCFullYear();
        var ho = o.getUTCMonth();
        var hetfo = napEltol(ma, -((o.getUTCDay() + 6) % 7));

        switch (kulcs) {
            case 'ma': return [ma, ma];
            case 'tegnap': return [napEltol(ma, -1), napEltol(ma, -1)];
            case 'het': return [hetfo, ma];
            case 'elozohet': return [napEltol(hetfo, -7), napEltol(hetfo, -1)];
            case 'ho': return [datumIso(new Date(Date.UTC(ev, ho, 1))), ma];
            case 'elozoho': return [datumIso(new Date(Date.UTC(ev, ho - 1, 1))), datumIso(new Date(Date.UTC(ev, ho, 0)))];
            case 'negyedev': return [datumIso(new Date(Date.UTC(ev, Math.floor(ho / 3) * 3, 1))), ma];
            case 'ev': return [ev + '-01-01', ma];
            case 'tavaly': return [(ev - 1) + '-01-01', (ev - 1) + '-12-31'];
            case '12ho': return [datumIso(new Date(Date.UTC(ev - 1, ho, o.getUTCDate() + 1))), ma];
            case 'mind': return ['2000-01-01', ma];
            default: return [egyeniTol || ma, egyeniIg || ma];
        }
    }

    function idoszakSav(cel, allapot) {
        return '<div class="sdh-pt-idoszak" data-pt-idoszak="' + cel + '">' +
            '<div class="sdh-pt-pillek sdh-pt-pillek--tord">' + ELORE.map(function (p) {
                return '<button type="button" class="sdh-pt-pill' + (allapot.elore === p[0] ? ' is-aktiv' : '') + '" data-pt-elore="' + p[0] + '">' + p[1] + '</button>';
            }).join('') + '</div>' +
            '<span class="sdh-pt-idoszak__egyeni"' + (allapot.elore === 'egyeni' ? '' : ' hidden') + '>' +
            '<input type="text" class="sdh-pop sdh-pop--datum" readonly data-sdh-pop="datum" data-sdh-pop-adat="[]" data-pt-tol value="' + e(allapot.tol) + '" aria-label="Kezdő nap"> – ' +
            '<input type="text" class="sdh-pop sdh-pop--datum" readonly data-sdh-pop="datum" data-sdh-pop-adat="[]" data-pt-ig value="' + e(allapot.ig) + '" aria-label="Utolsó nap">' +
            '</span></div>';
    }

    /* ---------------- Riport ---------------- */

    function riportTolt() {
        var r = S.riport;
        var hely = lapElem('riport');
        var tartomany = idoszak(r.elore, r.tol, r.ig);

        r.tol = tartomany[0];
        r.ig = tartomany[1];

        hely.innerHTML = idoszakSav('riport', r) + '<div data-pt-riport><div class="sdh-pt-toltes">Riport készül…</div></div>';

        kuld('riport', { tol: r.tol, ig: r.ig }).then(function (v) {
            riportRajzol(v);
        }).catch(function (h) {
            hely.querySelector('[data-pt-riport]').innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
        });
    }

    function riportRajzol(v) {
        var o = v.osszesen;
        var hely = lapElem('riport').querySelector('[data-pt-riport]');
        var kiadasok = v.kiadasok;
        var napok = v.napok.slice().reverse();

        S.riport.napok = napok;
        S.riport.oldal = 1;

        hely.innerHTML =
            '<div class="sdh-pt-riport__fej"><h2>' + e(v.tol.replace(/-/g, '.')) + '. – ' + e(v.ig.replace(/-/g, '.')) + '.</h2>' +
            '<span><button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--kicsi" data-pt-export="' + e(v.tol) + '|' + e(v.ig) + '">Excel (CSV) letöltés</button>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--kicsi" data-pt-nyomtat>Nyomtatás</button></span></div>' +
            '<div class="sdh-pt-kpik">' +
            kpi('Forgalom', penz(o.forgalom), o.napok + ' nyitvatartási nap · napi átlag ' + penz(o.atlag), 'fo') +
            kpi('KP bevétel', penz(o.kp_be), arany(o.kp_be, o.forgalom) + ' a forgalomból', 'kp') +
            kpi('Bankkártya', penz(o.kartya), arany(o.kartya, o.forgalom) + ' a forgalomból', 'kartya') +
            (o.utalas ? kpi('Utalás', penz(o.utalas), '', 'utalas') : '') +
            kpi('Kivét', penz(o.kivet), '') +
            kpi('Kifizetés', penz(o.kifizetes), '') +
            kpi('Nyitó → záró KP', penz(o.nyito || 0) + ' → ' + penz(o.zaro || 0), '') +
            kpi('Eltérések', o.elteres_nap ? elojeles(o.tobblet + o.hiany) : 'nincs', o.elteres_nap ? o.elteres_nap + ' nap · többlet ' + penz(o.tobblet) + ' · hiány ' + penz(o.hiany) : (o.szamolatlan ? o.szamolatlan + ' nap nem volt megszámolva' : '')) +
            '</div>' +
            '<div class="sdh-pt-racs2">' +
            '<section class="sdh-pt-kartya"><h3>Kivét személyenként</h3>' + listaHtml(kiadasok.kivet.map(function (k) {
                return [k.szemely, penz(k.osszeg), k.db + ' alkalom'];
            }), 'Nem volt kivét.') + '</section>' +
            '<section class="sdh-pt-kartya"><h3>Kifizetés partnerenként</h3>' + listaHtml(kiadasok.partnerek.slice(0, 12).map(function (k) {
                return [k.nev, penz(k.osszeg), k.db + ' tétel'];
            }), 'Nem volt kifizetés.') + '</section>' +
            '<section class="sdh-pt-kartya"><h3>Bizonylatok</h3>' + listaHtml([
                ['Bevételi tételek', szam(v.bizonylat.db) + ' db', ''],
                ['Számlaszám nélkül', szam(v.bizonylat.szamla_nelkul_db) + ' db', penz(v.bizonylat.szamla_nelkul)],
                ['Munkalap nélkül', szam(v.bizonylat.ml_nelkul_db) + ' db', '']
            ], '') +
            '<button type="button" class="sdh-pt-link" data-pt-kereses-szuro="szamla_nelkul" data-tol="' + e(v.tol) + '" data-ig="' + e(v.ig) + '">Számla nélküli tételek listája →</button></section>' +
            '</div>' +
            '<section class="sdh-pt-kartya"><h3>Napok</h3><div data-pt-riport-napok></div></section>';

        riportNapok();
    }

    function riportNapok() {
        var napok = S.riport.napok || [];
        var oldal = S.riport.oldal;
        var db = 31;
        var oldalak = Math.max(1, Math.ceil(napok.length / db));
        var szelet = napok.slice((oldal - 1) * db, oldal * db);
        var hely = lapElem('riport').querySelector('[data-pt-riport-napok]');

        hely.innerHTML = '<div class="sdh-pt-tabla-keret"><table class="sdh-pt-tabla sdh-pt-tabla--napok"><thead><tr>' +
            '<th>Nap</th><th class="sdh-pt-c-szam">Nyitó</th><th class="sdh-pt-c-szam">KP</th><th class="sdh-pt-c-szam">Kártya</th><th class="sdh-pt-c-szam">Utalás</th>' +
            '<th class="sdh-pt-c-szam">Kivét</th><th class="sdh-pt-c-szam">Kifizetés</th><th class="sdh-pt-c-szam">Forgalom</th><th class="sdh-pt-c-szam">Várható záró</th><th class="sdh-pt-c-szam">Számolt</th><th>Eltérés</th></tr></thead><tbody>' +
            (szelet.length ? szelet.map(function (n) {
                return '<tr data-pt-naplo-ugras="' + e(n.datum) + '" tabindex="0">' +
                    '<td>' + e(n.datum) + ' <span class="sdh-pt-halvany">' + e(hetnap(n.datum).slice(0, 3)) + '</span></td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam(n.nyito)) + '</td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam0(n.kp_be)) + '</td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam0(n.kartya)) + '</td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam0(n.utalas)) + '</td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam0(n.kivet)) + '</td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam0(n.kifizetes)) + '</td>' +
                    '<td class="sdh-pt-c-szam"><strong>' + e(szam0(n.forgalom)) + '</strong></td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam(n.zaro)) + '</td>' +
                    '<td class="sdh-pt-c-szam">' + (n.szamolt !== null ? e(szam(n.szamolt)) : '') + '</td>' +
                    '<td>' + elteresJel(n) + '</td></tr>';
            }).join('') : '<tr class="sdh-pt-ures"><td colspan="11">Ebben az időszakban nincs adat.</td></tr>') +
            '</tbody></table></div>' +
            (oldalak > 1 ? '<div class="sdh-pt-lapozo">' + lapozo(oldal, oldalak, 'riport', napok.length + ' nap') + '</div>' : '');
    }

    function arany(a, b) {
        return b ? Math.round(a / b * 100) + '%' : '–';
    }

    function listaHtml(sorok, ures) {
        if (!sorok.length) {
            return '<p class="sdh-pt-halvany">' + e(ures) + '</p>';
        }

        return '<ul class="sdh-pt-lista">' + sorok.map(function (s) {
            return '<li><span>' + e(s[0]) + '</span><strong>' + e(s[1]) + '</strong>' + (s[2] ? '<em>' + e(s[2]) + '</em>' : '') + '</li>';
        }).join('') + '</ul>';
    }

    /* ---------------- Statisztika ---------------- */

    function statTolt() {
        var st = S.stat;
        var hely = lapElem('statisztika');
        var t = idoszak(st.elore, st.tol, st.ig);

        st.tol = t[0];
        st.ig = t[1];

        hely.innerHTML = idoszakSav('statisztika', st) +
            '<div class="sdh-pt-pillek sdh-pt-bontas" data-pt-bontas>' + [['', 'Automatikus'], ['nap', 'Napi'], ['het', 'Heti'], ['honap', 'Havi'], ['ev', 'Éves']].map(function (b) {
                return '<button type="button" class="sdh-pt-pill' + (st.bontas === b[0] ? ' is-aktiv' : '') + '" data-pt-bontas-ertek="' + b[0] + '">' + b[1] + '</button>';
            }).join('') + '</div>' +
            '<div data-pt-stat><div class="sdh-pt-toltes">Számolás…</div></div>';

        kuld('statisztika', { tol: st.tol, ig: st.ig, bontas: st.bontas }).then(statRajzol).catch(function (h) {
            hely.querySelector('[data-pt-stat]').innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
        });
    }

    function valtozas(most, elozo) {
        if (!elozo) {
            return '';
        }

        var sz = Math.round((most - elozo) / Math.abs(elozo) * 100);

        return (sz > 0 ? '▲ ' : sz < 0 ? '▼ ' : '') + Math.abs(sz) + '% az előző időszakhoz képest';
    }

    function statRajzol(v) {
        var o = v.osszesen;
        var el = v.elozo;
        var hely = lapElem('statisztika').querySelector('[data-pt-stat]');
        var bontasNev = { nap: 'naponként', het: 'hetente', honap: 'havonta', ev: 'évente' }[v.bontas];
        var maxHet = Math.max.apply(null, v.hetnap.map(function (h) { return h.atlag; }).concat([1]));
        var katOssz = v.kategoriak.reduce(function (a, k) { return a + k.osszeg; }, 0) || 1;
        var vanOra = v.orak.some(function (x) { return x.db > 0; });

        hely.innerHTML =
            '<div class="sdh-pt-kpik">' +
            kpi('Forgalom', penz(o.forgalom), valtozas(o.forgalom, el.forgalom), 'fo') +
            kpi('Napi átlag', penz(o.atlag), o.napok + ' nyitvatartási nap') +
            kpi('KP / kártya arány', arany(o.kp_be, o.forgalom) + ' / ' + arany(o.kartya, o.forgalom), o.utalas ? 'utalás ' + arany(o.utalas, o.forgalom) : '') +
            kpi('Átlagos tétel', penz(v.tetel.atlag), 'medián ' + penz(v.tetel.median) + ' · ' + szam(v.tetel.db) + ' tétel') +
            kpi('Kivét', penz(o.kivet), valtozas(o.kivet, el.kivet)) +
            kpi('Kifizetés', penz(o.kifizetes), valtozas(o.kifizetes, el.kifizetes)) +
            kpi('Pénzmozgás egyenlege', penz(o.kp_be + o.kifizetes + o.kivet + o.befizetes), 'KP bevétel − kivét − kifizetés') +
            kpi('Eltérések', o.elteres_nap ? elojeles(o.tobblet + o.hiany) : 'nincs', o.elteres_nap ? o.elteres_nap + ' napon' : '') +
            '</div>' +
            '<section class="sdh-pt-kartya"><h3>Forgalom ' + e(bontasNev) + '</h3>' + oszlopDiagram(v.sor) + '</section>' +
            '<div class="sdh-pt-racs2">' +
            '<section class="sdh-pt-kartya"><h3>A hét napjai (átlagos napi forgalom)</h3>' +
            '<div class="sdh-pt-savok">' + v.hetnap.map(function (h) {
                return savSor(h.nap, h.atlag, maxHet, penz(h.atlag), h.napok + ' nap');
            }).join('') + '</div></section>' +
            '<section class="sdh-pt-kartya"><h3>Bevétel kategóriánként</h3>' +
            '<div class="sdh-pt-savok">' + v.kategoriak.map(function (k) {
                return savSor(k.nev, k.osszeg, v.kategoriak[0].osszeg, penz(k.osszeg), Math.round(k.osszeg / katOssz * 100) + '% · ' + k.db + ' db');
            }).join('') + '</div><p class="sdh-mezo__sugo">A leírás kulcsszavai alapján (Beállítások → Házipénztár).</p></section>' +
            '<section class="sdh-pt-kartya"><h3>Bizonylatoltság</h3>' +
            '<div class="sdh-pt-savok">' +
            savSor('Számlaszámmal', v.tetel.szamlas.db, v.tetel.db || 1, arany(v.tetel.szamlas.db, v.tetel.db), penz(v.tetel.szamlas.osszeg)) +
            savSor('Munkalaphoz kötve', v.tetel.mlhez.db, v.tetel.db || 1, arany(v.tetel.mlhez.db, v.tetel.db), penz(v.tetel.mlhez.osszeg)) +
            '</div></section>' +
            '<section class="sdh-pt-kartya"><h3>Kivét személyenként</h3>' + listaHtml(v.kiadasok.kivet.map(function (k) {
                return [k.szemely, penz(k.osszeg), k.db + ' alkalom · átlag ' + penz(k.osszeg / Math.max(1, k.db))];
            }), 'Nem volt kivét.') + '</section>' +
            '<section class="sdh-pt-kartya"><h3>Kifizetések – legnagyobb partnerek</h3>' + listaHtml(v.kiadasok.partnerek.slice(0, 10).map(function (k) {
                return [k.nev, penz(k.osszeg), k.db + ' tétel'];
            }), 'Nem volt kifizetés.') + '</section>' +
            '<section class="sdh-pt-kartya"><h3>Legnagyobb tételek</h3>' + listaHtml(v.legnagyobb.map(function (t) {
                return [t.datum + ' · ' + t.leiras, penz(t.osszeg), (t.ml ? 'ML ' + t.ml : '') + (t.szamla ? ' · ' + t.szamla : '')];
            }), 'Nincs adat.') +
            (v.legjobb ? '<p class="sdh-mezo__sugo">Legerősebb nap: ' + e(v.legjobb.datum) + ' – ' + e(penz(v.legjobb.forgalom)) + '</p>' : '') + '</section>' +
            (vanOra ? '<section class="sdh-pt-kartya"><h3>Forgalom óránként</h3><div class="sdh-pt-savok">' + v.orak.filter(function (x) { return x.db > 0; }).map(function (x) {
                return savSor(x.ora + ':00', x.osszeg, Math.max.apply(null, v.orak.map(function (y) { return y.osszeg; })), penz(x.osszeg), x.db + ' tétel');
            }).join('') + '</div><p class="sdh-mezo__sugo">Csak a CRM-ben rögzített tételekből (az importáltak ideje nem ismert).</p></section>' : '') +
            '<section class="sdh-pt-kartya"><h3>Legnagyobb eltérések</h3>' + listaHtml(v.eltero.map(function (x) {
                return [x.datum, elojeles(x.elteres), x.elteres > 0 ? 'többlet' : 'hiány'];
            }), 'Nem volt eltérés.') + '</section>' +
            '</div>' +
            (v.evek.length > 1 ? '<section class="sdh-pt-kartya"><h3>Évek összevetése</h3>' + evekHtml(v.evek) + '</section>' : '');
    }

    function savSor(cimke, ertek, max, felirat, al) {
        var sz = max > 0 ? Math.max(0, Math.min(100, ertek / max * 100)) : 0;

        return '<div class="sdh-pt-sav"><span class="sdh-pt-sav__cimke">' + e(cimke) + '</span>' +
            '<span class="sdh-pt-sav__sin"><span class="sdh-pt-sav__toltes" style="width:' + sz.toFixed(1) + '%"></span></span>' +
            '<span class="sdh-pt-sav__ertek">' + e(felirat) + (al ? '<em>' + e(al) + '</em>' : '') + '</span></div>';
    }

    function evekHtml(evek) {
        var max = 0;

        evek.forEach(function (ev) { ev.honapok.forEach(function (h) { max = Math.max(max, h); }); });

        return '<div class="sdh-pt-tabla-keret"><table class="sdh-pt-tabla sdh-pt-tabla--evek"><thead><tr><th>Év</th>' +
            HO_ROVID.map(function (h) { return '<th class="sdh-pt-c-szam">' + h + '</th>'; }).join('') +
            '<th class="sdh-pt-c-szam">Forgalom</th><th class="sdh-pt-c-szam">Napi átlag</th><th class="sdh-pt-c-szam">Kivét</th><th class="sdh-pt-c-szam">Kifizetés</th><th>Változás</th></tr></thead><tbody>' +
            evek.map(function (ev, i) {
                var elozo = evek[i - 1];

                return '<tr><th>' + e(ev.ev) + '</th>' + ev.honapok.map(function (h) {
                    var a = max > 0 ? h / max : 0;

                    return '<td class="sdh-pt-c-szam sdh-pt-ho" style="--a:' + a.toFixed(3) + '" title="' + e(penz(h)) + '">' + (h ? e(ezresRovid(h)) : '') + '</td>';
                }).join('') +
                    '<td class="sdh-pt-c-szam"><strong>' + e(szam(ev.forgalom)) + '</strong></td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam(ev.napok ? ev.forgalom / ev.napok : 0)) + '</td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam(ev.kivet)) + '</td>' +
                    '<td class="sdh-pt-c-szam">' + e(szam(ev.kifizetes)) + '</td>' +
                    '<td>' + (elozo && elozo.forgalom ? e(valtozas(ev.forgalom, elozo.forgalom).replace(' az előző időszakhoz képest', '')) : '') + '</td></tr>';
            }).join('') + '</tbody></table></div><p class="sdh-mezo__sugo">A cellák színe a havi forgalom nagysága (sötétebb = nagyobb). A folyó év utolsó hónapja nem teljes.</p>';
    }

    function ezresRovid(n) {
        if (Math.round(n) === 0) {
            return '0';
        }

        if (Math.abs(n) >= 1e6) {
            return (n / 1e6).toFixed(1).replace('.', ',') + ' M';
        }

        return Math.round(n / 1000) + ' e';
    }

    /**
     * Halmozott oszlopdiagram (KP, kártya, utalás) – egy tengely, vékony oszlopok,
     * 2 px rés a szegmensek között, rámutatásra a pontos számok.
     */
    function oszlopDiagram(sor) {
        if (!sor.length) {
            return '<p class="sdh-pt-halvany">Nincs adat.</p>';
        }

        var W = 960;
        var H = 260;
        var bal = 56;
        var lent = 26;
        var fent = 10;
        var max = Math.max.apply(null, sor.map(function (s) { return s.kp + s.kartya + s.utalas; }).concat([1]));
        var lepes = szepLepes(max / 4);
        var plafon = Math.ceil(max / lepes) * lepes;
        var sz = (W - bal - 8) / sor.length;
        var osz = Math.max(2, Math.min(28, sz * 0.62));
        var y = function (v) { return fent + (H - fent - lent) * (1 - v / plafon); };
        var vonalak = '';
        var oszlopok = '';
        var feliratok = '';
        var cimkeLepes = Math.max(1, Math.ceil(sor.length / 12));

        for (var v = 0; v <= plafon + 0.5; v += lepes) {
            vonalak += '<line class="sdh-pt-d__racs" x1="' + bal + '" x2="' + (W - 4) + '" y1="' + y(v).toFixed(1) + '" y2="' + y(v).toFixed(1) + '"/>' +
                '<text class="sdh-pt-d__tengely" x="' + (bal - 8) + '" y="' + (y(v) + 4).toFixed(1) + '" text-anchor="end">' + e(ezresRovid(v)) + '</text>';
        }

        sor.forEach(function (s, i) {
            var x = bal + i * sz + (sz - osz) / 2;
            var alap = 0;
            var reszek = [['kp', s.kp], ['kartya', s.kartya], ['utalas', s.utalas]];

            oszlopok += '<g class="sdh-pt-d__oszlop" data-pt-d="' + i + '">' +
                '<rect class="sdh-pt-d__hit" x="' + (bal + i * sz).toFixed(1) + '" y="' + fent + '" width="' + sz.toFixed(1) + '" height="' + (H - fent - lent) + '"/>';

            reszek.forEach(function (r, ri) {
                if (r[1] <= 0) {
                    return;
                }

                var y1 = y(alap + r[1]);
                var y0 = y(alap);
                var magas = Math.max(1, y0 - y1 - (alap > 0 ? 2 : 0));
                var felso = ri === reszek.length - 1 || reszek.slice(ri + 1).every(function (q) { return q[1] <= 0; });

                oszlopok += '<rect class="sdh-pt-d__' + r[0] + '" x="' + x.toFixed(1) + '" y="' + y1.toFixed(1) + '" width="' + osz.toFixed(1) + '" height="' + magas.toFixed(1) + '"' +
                    (felso && osz >= 8 ? ' rx="3"' : '') + '/>';
                alap += r[1];
            });

            oszlopok += '</g>';

            if (i % cimkeLepes === 0) {
                feliratok += '<text class="sdh-pt-d__tengely" x="' + (bal + i * sz + sz / 2).toFixed(1) + '" y="' + (H - 6) + '" text-anchor="middle">' + e(rovidKulcs(s.kulcs)) + '</text>';
            }
        });

        return '<div class="sdh-pt-d" data-pt-diagram>' +
            '<div class="sdh-pt-d__jelmagyarazat"><span class="is-kp">Készpénz</span><span class="is-kartya">Bankkártya</span><span class="is-utalas">Utalás</span></div>' +
            '<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="Forgalom oszlopdiagram">' + vonalak +
            '<line class="sdh-pt-d__alap" x1="' + bal + '" x2="' + (W - 4) + '" y1="' + y(0) + '" y2="' + y(0) + '"/>' + oszlopok + feliratok + '</svg>' +
            '<div class="sdh-pt-d__tipp" hidden></div>' +
            '<script type="application/json" data-pt-d-adat>' + JSON.stringify(sor).replace(/</g, '\\u003c') + '</script>' +
            '</div>';
    }

    function szepLepes(n) {
        var hatvany = Math.pow(10, Math.floor(Math.log10(Math.max(1, n))));
        var t = n / hatvany;

        return (t <= 1 ? 1 : t <= 2 ? 2 : t <= 2.5 ? 2.5 : t <= 5 ? 5 : 10) * hatvany;
    }

    function rovidKulcs(k) {
        if (/^\d{4}-\d{2}-\d{2}$/.test(k)) {
            return datumRovid(k);
        }

        if (/^\d{4}-\d{2}$/.test(k)) {
            return HO_ROVID[+k.slice(5) - 1] + (k.slice(5) === '01' ? ' ' + k.slice(0, 4) : '');
        }

        return k.replace('-H', '/');
    }

    function kulcsHosszu(k) {
        if (/^\d{4}-\d{2}-\d{2}$/.test(k)) {
            return datumHosszu(k);
        }

        if (/^\d{4}-\d{2}$/.test(k)) {
            return k.slice(0, 4) + '. ' + HONAPOK[+k.slice(5) - 1];
        }

        if (/-H/.test(k)) {
            return k.slice(0, 4) + '. ' + k.slice(6) + '. hét';
        }

        return k;
    }

    lapElem('statisztika').addEventListener('mousemove', function (esemeny) {
        var g = esemeny.target.closest && esemeny.target.closest('[data-pt-d]');
        var d = esemeny.target.closest && esemeny.target.closest('[data-pt-diagram]');

        if (!d) {
            return;
        }

        var tipp = d.querySelector('.sdh-pt-d__tipp');

        Array.prototype.forEach.call(d.querySelectorAll('.sdh-pt-d__oszlop.is-folotte'), function (x) { x.classList.remove('is-folotte'); });

        if (!g) {
            tipp.hidden = true;

            return;
        }

        var adat = JSON.parse(d.querySelector('[data-pt-d-adat]').textContent || '[]')[+g.getAttribute('data-pt-d')];

        if (!adat) {
            return;
        }

        g.classList.add('is-folotte');
        tipp.innerHTML = '<strong>' + e(kulcsHosszu(adat.kulcs)) + '</strong>' +
            '<span class="is-kp">KP <b>' + e(penz(adat.kp)) + '</b></span>' +
            '<span class="is-kartya">Kártya <b>' + e(penz(adat.kartya)) + '</b></span>' +
            (adat.utalas ? '<span class="is-utalas">Utalás <b>' + e(penz(adat.utalas)) + '</b></span>' : '') +
            '<span>Összesen <b>' + e(penz(adat.forgalom)) + '</b></span>' +
            (adat.kivet ? '<span>Kivét <b>' + e(penz(adat.kivet)) + '</b></span>' : '') +
            '<span>' + adat.napok + ' nap</span>';
        tipp.hidden = false;

        var keret = d.getBoundingClientRect();
        var x = esemeny.clientX - keret.left;

        tipp.style.left = Math.min(keret.width - tipp.offsetWidth - 4, Math.max(4, x + 14)) + 'px';
        tipp.style.top = Math.max(0, esemeny.clientY - keret.top - tipp.offsetHeight - 10) + 'px';
    });

    /* ---------------- Ügynök ---------------- */

    function ugynokTolt(datum) {
        if (typeof datum === 'string' && datum) {
            S.ugynok.datum = datum;
        }

        var hely = lapElem('ugynok');

        hely.innerHTML =
            '<div class="sdh-pt-ugynok">' +
            '<div class="sdh-pt-ugynok__fo">' +
            '  <div class="sdh-pt-ugynok__bemutat">' + IKON.ugynok + '<div><h2>Pénztár-ügynök</h2><p>Összeveti a pénztárat a munkalapokkal és a számlákkal: megkeresi, mi hiányzik, mi került be kétszer vagy rossz módon, és ha a kassza nem stimmel, kiszámolja, mi adja ki az eltérést. Csak javasol – minden javítást te hagysz jóvá.</p></div></div>' +
            '  <form class="sdh-pt-kerdes" data-pt-kerdes><input type="text" name="q" autocomplete="off" placeholder="Kérdezz: „hol van a 34977 pénze?”, „miért hiányzik 12 000 Ft?”, „SD-2026-1745”…">' +
            '  <button type="submit" class="sdh-gomb sdh-gomb--elsodleges">Kérdés</button></form>' +
            '  <div data-pt-valasz></div>' +
            '  <div class="sdh-pt-ugynok__nap"><span>Nap egyeztetése:</span>' +
            '    <input type="text" class="sdh-pop sdh-pop--datum" readonly data-sdh-pop="datum" data-sdh-pop-adat="[]" data-pt-ugynok-datum value="' + e(S.ugynok.datum) + '">' +
            '    <button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--kicsi" data-pt-egyeztet-most>Egyeztetés</button></div>' +
            '  <div data-pt-egyeztetes><div class="sdh-pt-toltes">Egyeztetés…</div></div>' +
            '</div>' +
            '<aside class="sdh-pt-ugynok__ugyek" data-pt-ugyek><div class="sdh-pt-toltes">Nyitott ügyek…</div></aside>' +
            '</div>';

        egyeztetesTolt();
        ugyekTolt();
    }

    function egyeztetesTolt() {
        var hely = lapElem('ugynok').querySelector('[data-pt-egyeztetes]');

        kuld('egyeztet', { datum: S.ugynok.datum }).then(function (v) {
            hely.innerHTML = '<h3 class="sdh-pt-alcim2">' + e(datumHosszu(v.datum)) + '</h3>' + egyeztetesHtml(v, false);
            hely.sdhEgyeztetes = v;
        }).catch(function (h) {
            hely.innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
        });
    }

    function egyeztetesHtml(v, rovid) {
        var fajta = v.elteres === null ? 'semleges' : (Math.abs(v.elteres) < 1 ? 'ok' : (v.elteres > 0 ? 'tobb' : 'hiany'));

        return '<div class="sdh-pt-osszegzes sdh-pt-osszegzes--' + fajta + '">' + (fajta === 'ok' ? IKON.pipa : fajta === 'semleges' ? IKON.info : IKON.figyelem) +
            '<p>' + e(v.osszegzes) + '</p></div>' +
            (v.magyarazatok.length ? '<div class="sdh-pt-magyarazat"><h4>Pontos magyarázat</h4><ul>' + v.magyarazatok.map(function (m) {
                return '<li>' + e(m.szoveg) + ' = <strong>' + e(elojeles(m.osszeg)) + '</strong></li>';
            }).join('') + '</ul></div>' : '') +
            (v.megallapitasok.length ? '<ul class="sdh-pt-megall">' + v.megallapitasok.map(function (m, i) {
                return '<li class="sdh-pt-megall__elem sdh-pt-megall__elem--' + e(m.szint) + '">' +
                    '<span class="sdh-pt-megall__ikon">' + (IKON[m.szint] || IKON.info) + '<span>' + ({ hiba: 'Fontos', figyelem: 'Figyelem', info: 'Infó' }[m.szint] || '') + '</span></span>' +
                    '<div class="sdh-pt-megall__szoveg"><strong>' + e(m.cim) + '</strong><span>' + e(m.szoveg) + '</span>' +
                    (Math.abs(m.hatas) >= 1 ? '<em>A kasszára gyakorolt hatása, ha javítod: ' + e(elojeles(m.hatas)) + '</em>' : '') + '</div>' +
                    '<div class="sdh-pt-megall__gombok">' + (m.javaslatok || []).map(function (j, ji) {
                        return '<button type="button" class="sdh-gomb sdh-gomb--kicsi' + (ji === 0 ? '' : ' sdh-gomb--vilagos') + '" data-pt-javaslat="' + i + ':' + ji + '">' + e(j.cimke) + '</button>';
                    }).join('') + '</div></li>';
            }).join('') + '</ul>' : (rovid ? '' : '<p class="sdh-pt-halvany">Nincs megállapítás erre a napra.</p>'));
    }

    function ugyekTolt() {
        var hely = lapElem('ugynok').querySelector('[data-pt-ugyek]');

        kuld('ugyek', {}).then(function (u) {
            hely.sdhUgyek = u;
            hely.innerHTML = '<h3>Nyitott ügyek <span class="sdh-pt-halvany">' + e(u.tol) + ' óta</span></h3>' +
                '<section><h4>Fizetett munkalap, a pénztárban nincs (' + u.hianyzo.length + ')</h4>' +
                (u.hianyzo.length ? '<ul class="sdh-pt-ugylista">' + u.hianyzo.slice(0, 30).map(function (m, i) {
                    return '<li><span><strong>ML ' + e(m.szam) + '</strong> · ' + e(m.nev || 'névtelen') + '<em>' + e(m.datum + ' · ' + (m.fizetesi_mod || 'mód nincs')) + '</em></span>' +
                        '<b>' + e(penz(m.hiany)) + '</b><button type="button" class="sdh-gomb sdh-gomb--kicsi" data-pt-ugy-felvesz="' + i + '">Beírás</button></li>';
                }).join('') + '</ul>' : '<p class="sdh-pt-halvany">' + IKON.pipa + ' Nincs ilyen.</p>') + '</section>' +
                '<section><h4>Számlaszám pótolható (' + u.szamlaszam.length + ')</h4>' +
                (u.szamlaszam.length ? '<ul class="sdh-pt-ugylista">' + u.szamlaszam.slice(0, 20).map(function (s) {
                    return '<li><span>' + e(s.datum) + ' · ' + e(s.leiras) + '<em>ML ' + e(s.ml) + ' → ' + e(s.szamla) + '</em></span><b>' + e(penz(s.osszeg)) + '</b></li>';
                }).join('') + '</ul><button type="button" class="sdh-gomb sdh-gomb--kicsi" data-pt-szamla-mind>Mind beírása (' + u.szamlaszam.length + ')</button>' : '<p class="sdh-pt-halvany">' + IKON.pipa + ' Nincs ilyen.</p>') + '</section>' +
                '<section><h4>Eltéréssel zárt napok (' + u.eltero_napok.length + ')</h4>' +
                (u.eltero_napok.length ? '<ul class="sdh-pt-ugylista">' + u.eltero_napok.map(function (n) {
                    return '<li><span>' + e(datumHosszu(n.datum)) + '</span><b class="' + (n.elteres > 0 ? 'is-tobb' : 'is-hiany') + '">' + e(elojeles(n.elteres)) + '</b>' +
                        '<button type="button" class="sdh-gomb sdh-gomb--kicsi sdh-gomb--vilagos" data-pt-egyeztet="' + e(n.datum) + '">Egyeztetés</button></li>';
                }).join('') + '</ul>' : '<p class="sdh-pt-halvany">' + IKON.pipa + ' Nincs ilyen.</p>') + '</section>' +
                '<section><h4>Lezáratlan napok (' + u.lezaratlan.length + ')</h4>' +
                (u.lezaratlan.length ? '<ul class="sdh-pt-ugylista">' + u.lezaratlan.map(function (d) {
                    return '<li><span>' + e(datumHosszu(d)) + '</span><button type="button" class="sdh-gomb sdh-gomb--kicsi sdh-gomb--vilagos" data-pt-kp-nap="' + e(d) + '">Zárás</button></li>';
                }).join('') + '</ul>' : '<p class="sdh-pt-halvany">' + IKON.pipa + ' Minden nap lezárva.</p>') + '</section>';
        }).catch(function (h) {
            hely.innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
        });
    }

    function javaslatVegrehajt(j, gomb) {
        var a = j.adat || {};

        switch (j.tipus) {
            case 'felvesz':
                ujTetel(a.tipus || 'bevetel', a);
                break;
            case 'munkalap':
                app().nyit('munkalapok', a.id, {});
                break;
            case 'tetel':
                tetelNyit(a.id);
                break;
            case 'kereses':
                S.kereses.q = a.q;
                gyoker.querySelector('[data-pt-kereso] input').value = a.q;
                lapra('kereses');
                break;
            case 'nap':
                lapra('ugynok', a.datum);
                break;
            case 'valtozasok':
                valtozasokAblak(a.datum);
                break;
            case 'visszaallit':
                gomb.disabled = true;
                kuld('torol', { id: a.id, vissza: '1' }).then(function () {
                    toast('Visszaállítva.', 'siker');
                    frissitAktiv();
                }).catch(function (h) { gomb.disabled = false; toast(h.message, 'hiba'); });
                break;
            case 'javit':
                gomb.disabled = true;
                kuld('javit', { muvelet: a.muvelet, id: a.id, ertek: a.ertek || '' }).then(function (v) {
                    toast(v.kesz ? 'Javítva.' : 'Nem volt mit javítani.', v.kesz ? 'siker' : '');
                    frissitAktiv();
                }).catch(function (h) { gomb.disabled = false; toast(h.message, 'hiba'); });
                break;
        }
    }

    /* ---------------- Keresés ---------------- */

    function keresesTolt() {
        var k = S.kereses;
        var hely = lapElem('kereses');
        var sz = k.szuro;

        gyoker.querySelector('[data-pt-kereso] input').value = k.q;

        hely.innerHTML =
            '<form class="sdh-pt-szurok" data-pt-szurok>' +
            '<label>Mit<input type="search" name="q" value="' + e(k.q) + '" placeholder="Számlaszám, munkalap, név, összeg…"></label>' +
            '<label>Tól<input type="text" class="sdh-pop sdh-pop--datum" readonly data-sdh-pop="datum" data-sdh-pop-adat="[]" name="tol" value="' + e(sz.tol || '') + '"></label>' +
            '<label>Ig<input type="text" class="sdh-pop sdh-pop--datum" readonly data-sdh-pop="datum" data-sdh-pop-adat="[]" name="ig" value="' + e(sz.ig || '') + '"></label>' +
            '<label>Összeg (min)<input type="text" inputmode="decimal" name="min" value="' + e(sz.min || '') + '"></label>' +
            '<label>Összeg (max)<input type="text" inputmode="decimal" name="max" value="' + e(sz.max || '') + '"></label>' +
            '<div class="sdh-pt-szurok__sor">' +
            '<span class="sdh-pt-pillek">' + [['bevetel', 'Bevétel'], ['kivet', 'Kivét'], ['kifizetes', 'Kifizetés'], ['befizetes', 'Befizetés'], ['kihagyva', 'Nem került kasszába']].map(function (t) {
                var be = (sz.tipus || []).indexOf(t[0]) !== -1;

                return '<label class="sdh-pt-pill' + (be ? ' is-aktiv' : '') + '"><input type="checkbox" name="tipus" value="' + t[0] + '"' + (be ? ' checked' : '') + '><span>' + t[1] + '</span></label>';
            }).join('') + '</span>' +
            '<span class="sdh-pt-pillek">' + [['', 'Bármely mód'], ['kp', 'KP'], ['kartya', 'Kártya'], ['utalas', 'Utalás']].map(function (t) {
                var be = (sz.mod || '') === t[0];

                return '<label class="sdh-pt-pill' + (be ? ' is-aktiv' : '') + '"><input type="radio" name="mod" value="' + t[0] + '"' + (be ? ' checked' : '') + '><span>' + t[1] + '</span></label>';
            }).join('') + '</span>' +
            '<span class="sdh-pt-pillek">' + [['szamla_nelkul', 'Számla nélkül'], ['ml_nelkul', 'Munkalap nélkül'], ['torolt', 'Törölt tételek']].map(function (t) {
                var be = sz[t[0]] === '1';

                return '<label class="sdh-pt-pill' + (be ? ' is-aktiv' : '') + '"><input type="checkbox" name="' + t[0] + '" value="1"' + (be ? ' checked' : '') + '><span>' + t[1] + '</span></label>';
            }).join('') + '</span>' +
            '<button type="submit" class="sdh-gomb sdh-gomb--elsodleges sdh-gomb--kicsi">Keresés</button>' +
            '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--kicsi" data-pt-szuro-torol>Szűrők törlése</button>' +
            '</div></form>' +
            '<div data-pt-talalatok><div class="sdh-pt-toltes">Keresés…</div></div>';

        var m = { q: k.q, oldal: k.oldal };

        Object.keys(sz).forEach(function (kk) { m[kk] = sz[kk]; });

        kuld('kereses', m).then(keresesRajzol).catch(function (h) {
            hely.querySelector('[data-pt-talalatok]').innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
        });
    }

    function keresesRajzol(v) {
        var hely = lapElem('kereses').querySelector('[data-pt-talalatok]');
        var kap = v.kapcsolodo || { munkalapok: [], szamlak: [] };
        var utalas = v.tetelek.some(function (t) { return Math.round(t.utalas) !== 0; });

        S.kereses.mlCrm = v.ml_crm || {};

        hely.innerHTML =
            (kap.munkalapok.length ? kap.munkalapok.map(function (m) {
                var hiany = m.fizetve ? m.brutto - m.kasszaban : 0;

                return '<div class="sdh-pt-kapcs' + (hiany >= 1 ? ' is-hiany' : '') + '"><div><strong>Munkalap ' + e(m.szam) + '</strong> · ' + e(m.nev || 'névtelen') + ' · ' + e(m.leiras) +
                    '<span>Érték ' + e(penz(m.brutto)) + ' · előleg ' + e(penz(m.eloleg)) + ' · ' + (m.fizetve ? 'fizetve ' + e(m.fizetes_ideje || '') : 'nincs fizetve') + (m.fizetesi_mod ? ' (' + e(m.fizetesi_mod) + ')' : '') +
                    ' · pénztárban: <b>' + e(penz(m.kasszaban)) + '</b>' + (m.szamla ? ' · számla ' + e(m.szamla) : '') + '</span>' +
                    (hiany >= 1 ? '<em>' + IKON.figyelem + 'Fizetve, de ' + e(penz(hiany)) + ' nincs a pénztárban.</em>' : '') + '</div>' +
                    '<div class="sdh-pt-kapcs__gombok"><button type="button" class="sdh-gomb sdh-gomb--kicsi sdh-gomb--vilagos" data-pt-munkalap="' + m.id + '">Munkalap</button>' +
                    (hiany >= 1 ? '<button type="button" class="sdh-gomb sdh-gomb--kicsi" data-pt-felvesz-ml="' + e(m.szam) + '" data-osszeg="' + Math.round(hiany) + '" data-mod="' + e(m.mod) + '">Beírás a pénztárba</button>' : '') +
                    '</div></div>';
            }).join('') : '') +
            (kap.szamlak.length ? '<div class="sdh-pt-kapcs sdh-pt-kapcs--szamlak"><div><strong>Számlák a CRM-ben</strong><ul>' + kap.szamlak.map(function (s) {
                return '<li>' + e(s.szamlaszam) + ' · ' + e(s.kelt) + ' · ' + e(penz(s.brutto)) + ' · ' + e(s.fizmod) + (s.munkalap_szam ? ' · ML ' + e(s.munkalap_szam) : '') +
                    (s.kasszaban ? ' <span class="sdh-pt-jel sdh-pt-jel--ok">' + IKON.pipa + 'pénztárban</span>' : ' <span class="sdh-pt-jel sdh-pt-jel--hiany">nincs a pénztárban</span>') + '</li>';
            }).join('') + '</ul></div></div>' : '') +
            '<div class="sdh-pt-talalat-fej"><strong>' + szam(v.osszes) + ' találat</strong>' +
            (v.osszes ? '<span>KP ' + e(penz(v.osszeg.kp)) + ' · kártya ' + e(penz(v.osszeg.kartya)) + (v.osszeg.utalas ? ' · utalás ' + e(penz(v.osszeg.utalas)) : '') + (v.osszeg.ki ? ' · kiadás ' + e(penz(v.osszeg.ki)) : '') + '</span>' : '') +
            (v.oldalak > 1 ? '<span class="sdh-pt-lapozo">' + lapozo(v.oldal, v.oldalak, 'kereses', '') + '</span>' : '') + '</div>' +
            (v.tetelek.length ? '<div class="sdh-pt-tabla-keret"><table class="sdh-pt-tabla' + (utalas ? ' van-utalas' : '') + '">' + tablaFej({ datum: true }) + '<tbody>' +
                v.tetelek.map(function (t) { return tetelSor(t, { datum: true, q: v.fajta === 'szoveg' || v.fajta === 'szamla' ? v.q : '', mlCrm: S.kereses.mlCrm }); }).join('') +
                '</tbody></table></div>'
                : '<div class="sdh-pt-ures-doboz">Nincs találat a pénztárban.' + (v.fajta === 'szam' ? ' Ha ez munkalapszám, a fenti kártya mutatja, mi van róla a CRM-ben.' : '') +
                  '<br><button type="button" class="sdh-pt-link" data-pt-kerdes-ezt="' + e(v.q) + '">Kérdezd az ügynököt erről →</button></div>');
    }

    /* ---------------- Ablakok: tétel, változásnapló, import ---------------- */

    function ujTetel(tipus, elotolt) {
        var p = { tipus: tipus || 'bevetel' };

        Object.keys(elotolt || {}).forEach(function (k) {
            if (elotolt[k] !== undefined && elotolt[k] !== null && elotolt[k] !== '') {
                p[k] = elotolt[k];
            }
        });

        app().nyit('penztar', 0, { parameterek: p, siker: tetelMentve });
    }

    function tetelNyit(id) {
        app().nyit('penztar', id, { siker: tetelMentve });
    }

    function tetelMentve(adat) {
        toast('Elmentve.', 'siker');

        if (adat && adat.datum && adat.datum !== S.ma && S.lap === 'ma') {
            toast('A tétel a ' + adat.datum + ' napra került.', '');
        }

        frissitAktiv();
    }

    function frissitAktiv() {
        betoltve = {};

        if (S.lap === 'ma') {
            maFrissit();
        } else {
            maFrissit();
            lapra(S.lap);
        }
    }

    document.addEventListener('sdh:penztar-valtozott', frissitAktiv);

    function valtozasokAblak(datum) {
        var d = ablak('Változásnapló – ' + datumHosszu(datum), '<div class="sdh-pt-toltes">Betöltés…</div>', 'sdh-pt-ablak--szeles');
        var hely = d.querySelector('[data-pt-ablak-tartalom]');
        var NEVEK = { felvitel: 'Felvitel', modositas: 'Módosítás', torles: 'Törlés', visszaallitas: 'Visszaállítás', szamolas: 'Pénzszámolás', zaras: 'Zárás', ujranyitas: 'Újranyitás', szamlaszam: 'Számlaszám beírva' };

        kuld('valtozasok', { datum: datum }).then(function (v) {
            if (!v.valtozasok.length) {
                hely.innerHTML = '<p class="sdh-pt-halvany">Ezen a napon nem volt változás a CRM-ben.</p>';

                return;
            }

            var lapok = [];

            for (var i = 0; i < v.valtozasok.length; i += 12) {
                lapok.push(v.valtozasok.slice(i, i + 12));
            }

            var oldal = 0;

            function rajzol() {
                hely.innerHTML = '<ul class="sdh-pt-valtozasok">' + lapok[oldal].map(function (x) {
                    var t = x.uj || x.regi || {};
                    var mi = t.leiras ? '„' + t.leiras + '" ' + penz((t.kp || 0) + (t.kartya || 0) + (t.utalas || 0)) : (t.szamolt !== undefined && t.szamolt !== null ? 'számolt: ' + penz(t.szamolt) + (t.elteres ? ' · eltérés ' + elojeles(t.elteres) : '') : (t.szamla || ''));
                    var valt = '';

                    if (x.muvelet === 'modositas' && x.regi && x.uj) {
                        valt = Object.keys(x.uj).filter(function (k) { return ['leiras', 'nev', 'kp', 'kartya', 'utalas', 'ml', 'szamla', 'datum', 'szemely', 'tipus'].indexOf(k) !== -1 && String(x.uj[k]) !== String(x.regi[k]); })
                            .map(function (k) { return k + ': ' + x.regi[k] + ' → ' + x.uj[k]; }).join(' · ');
                    }

                    return '<li><span class="sdh-pt-valtozasok__ido">' + e((x.ido || '').slice(11, 16)) + '</span><strong>' + e(NEVEK[x.muvelet] || x.muvelet) + '</strong>' +
                        '<span>' + e(mi) + (valt ? '<em>' + e(valt) + '</em>' : '') + '</span><span class="sdh-pt-halvany">' + e(x.ki || '') + '</span></li>';
                }).join('') + '</ul>' +
                    (lapok.length > 1 ? '<div class="sdh-pt-lapozo"><button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--ikon" data-v="-1"' + (oldal === 0 ? ' disabled' : '') + '>' + IKON.balra + '</button>' +
                        '<span class="sdh-pt-lapozo__szoveg">' + (oldal + 1) + ' / ' + lapok.length + '</span>' +
                        '<button type="button" class="sdh-gomb sdh-gomb--vilagos sdh-gomb--ikon" data-v="1"' + (oldal >= lapok.length - 1 ? ' disabled' : '') + '>' + IKON.jobbra + '</button></div>' : '');
            }

            hely.addEventListener('click', function (ev) {
                var g = ev.target.closest('[data-v]');

                if (g) {
                    oldal += +g.getAttribute('data-v');
                    rajzol();
                }
            });

            rajzol();
        }).catch(function (h) {
            hely.innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
        });
    }

    function importAblak() {
        var d = ablak('Import a régi pénztártáblából',
            '<div data-pt-import>' +
            '<p>Válaszd ki a régi „Zárás" Excel-fájlt (.xlsx) vagy CSV-t. Előbb megmutatom, mi kerülne be – csak az „Importálás" gombra ír az adatbázisba.</p>' +
            '<div class="sdh-pt-feltolto" data-pt-ejto>' +
            '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-pt-fajl-valaszt>Fájl kiválasztása</button>' +
            '<span>vagy húzd ide a fájlt</span>' +
            '<input type="file" accept=".xlsx,.csv" hidden data-pt-fajl>' +
            '</div>' +
            '<div class="sdh-pt-haladas" hidden data-pt-haladas><span></span></div>' +
            '<p class="sdh-pt-halvany" data-pt-import-allapot></p>' +
            '</div>', 'sdh-pt-ablak--szeles');

        var hely = d.querySelector('[data-pt-import]');
        var fajlMezo = hely.querySelector('[data-pt-fajl]');
        var ejto = hely.querySelector('[data-pt-ejto]');
        var haladas = hely.querySelector('[data-pt-haladas]');
        var allapot = hely.querySelector('[data-pt-import-allapot]');
        var kesz = false;

        function sav(arany) {
            haladas.hidden = false;
            haladas.firstChild.style.width = Math.round(arany * 100) + '%';
        }

        function feltolt(fajl) {
            if (!fajl) {
                return;
            }

            ejto.hidden = true;
            allapot.textContent = 'Feltöltés: ' + fajl.name + '…';
            sav(0);

            kuld('import_feltolt', {}, fajl, function (a) {
                sav(a * 0.6);

                if (a >= 1) {
                    allapot.textContent = 'Beolvasás és ellenőrzés…';
                }
            }).then(function (v) {
                sav(1);
                elonezet(v);
            }).catch(function (h) {
                ejto.hidden = false;
                haladas.hidden = true;
                allapot.innerHTML = '<span class="sdh-pt-hiba">' + e(h.message) + '</span>';
            });
        }

        function elonezet(v) {
            haladas.hidden = true;

            var o = v.osszeg;

            hely.innerHTML =
                '<p><strong>' + e(v.fajl) + '</strong> · beolvasva ' + e(String(v.mp).replace('.', ',')) + ' mp alatt</p>' +
                '<div class="sdh-pt-kpik sdh-pt-kpik--tomor">' +
                kpi('Tételsor', szam(v.db), v.napok + ' nap') +
                kpi('Új', szam(v.uj), v.mar_bent ? szam(v.mar_bent) + ' már bent van – kimarad' : 'mind új', 'fo') +
                kpi('Időszak', v.tol + ' – ' + v.ig, '') +
                kpi('KP bevétel', penz(o.kp), '', 'kp') +
                kpi('Bankkártya', penz(o.kartya), '', 'kartya') +
                kpi('Kivét', penz(o.kivet), Object.keys(v.kivet || {}).map(function (k) { return k + ': ' + penz(v.kivet[k]); }).join(' · ')) +
                kpi('Kifizetés', penz(o.kifizetes), '') +
                kpi('Kezdő egyenleg', penz(v.nyito), v.zarasok ? v.zarasok + ' nap záró egyenlege a táblából' : '') +
                '</div>' +
                (v.figyelmeztetes.length ? '<div class="sdh-uzenet sdh-uzenet--figyelem">' + v.figyelmeztetes.map(e).join('<br>') + '</div>' : '') +
                '<details class="sdh-pt-minta"><summary>Minta (az első és az utolsó 5 sor)</summary><table class="sdh-pt-tabla"><tbody>' + v.minta.map(function (s) {
                    return '<tr><td>' + e(s.datum) + '</td><td>' + e(s.leiras) + (s.szemely ? ' <span class="sdh-pt-cimke sdh-pt-cimke--kivet">' + e(s.szemely) + '</span>' : '') + '</td><td class="sdh-pt-c-szam">' + e(szam0(s.kp)) + '</td><td class="sdh-pt-c-szam">' + e(szam0(s.kartya)) + '</td><td>' + e(s.ml) + '</td><td>' + e(s.szamla) + '</td></tr>';
                }).join('') + '</tbody></table></details>' +
                (v.van_import ? '<label class="sdh-jelolo sdh-pt-csere"><input type="checkbox" data-pt-csere> A korábban importált ' + szam(v.van_import) + ' sor cseréje (a CRM-ben rögzített tételek maradnak)</label>' : '') +
                '<div class="sdh-pt-haladas" hidden data-pt-haladas><span></span></div><p class="sdh-pt-halvany" data-pt-import-allapot></p>' +
                '<div class="sdh-urlap__lablec"><button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-pt-import-indit' + (v.uj || v.van_import ? '' : ' disabled') + '>Importálás (' + szam(v.uj) + ' sor)</button>' +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-pt-import-megse>Mégsem</button></div>';

            haladas = hely.querySelector('[data-pt-haladas]');
            allapot = hely.querySelector('[data-pt-import-allapot]');

            hely.querySelector('[data-pt-import-megse]').addEventListener('click', function () { d.close(); });
            hely.querySelector('[data-pt-import-indit]').addEventListener('click', function () {
                var gomb = this;
                var csere = hely.querySelector('[data-pt-csere]');

                gomb.disabled = true;
                hely.querySelector('[data-pt-import-megse]').disabled = true;
                lepes(v.token, 0, csere && csere.checked, v.db, 0);
            });
        }

        function lepes(token, tol, csere, db, beirva) {
            sav(Math.min(1, tol / Math.max(1, db)));
            allapot.textContent = 'Beírás: ' + szam(Math.min(tol, db)) + ' / ' + szam(db) + '…';

            kuld('import_lepes', { token: token, tol: tol, csere: csere ? '1' : '' }).then(function (v) {
                beirva += v.beirva;

                if (!v.kesz) {
                    lepes(token, v.kovetkezo, false, db, beirva);

                    return;
                }

                sav(1);
                kesz = true;
                allapot.innerHTML = IKON.pipa + ' Kész: ' + szam(beirva) + ' új sor került be. A napok összesítői és a záró egyenlegek elkészültek.';
                hely.querySelector('.sdh-urlap__lablec').innerHTML = '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-pt-import-bezar>Bezárás</button>';
                hely.querySelector('[data-pt-import-bezar]').addEventListener('click', function () { d.close(); });
            }).catch(function (h) {
                allapot.innerHTML = '<span class="sdh-pt-hiba">' + e(h.message) + '</span>';
            });
        }

        hely.querySelector('[data-pt-fajl-valaszt]').addEventListener('click', function () { fajlMezo.click(); });
        fajlMezo.addEventListener('change', function () { feltolt(fajlMezo.files[0]); });

        ['dragover', 'dragenter'].forEach(function (t) {
            ejto.addEventListener(t, function (ev) { ev.preventDefault(); ejto.classList.add('is-folotte'); });
        });
        ejto.addEventListener('dragleave', function () { ejto.classList.remove('is-folotte'); });
        ejto.addEventListener('drop', function (ev) {
            ev.preventDefault();
            ejto.classList.remove('is-folotte');
            feltolt(ev.dataTransfer.files[0]);
        });

        if (indulo.van_adat) {
            var vissza = document.createElement('p');

            vissza.className = 'sdh-pt-import-vissza';
            vissza.innerHTML = '<button type="button" class="sdh-pt-link sdh-pt-veszely" data-pt-import-visszavon>Korábbi import visszavonása…</button>';
            hely.appendChild(vissza);

            vissza.querySelector('button').addEventListener('click', function () {
                var g = this;

                if (!g.classList.contains('is-biztos')) {
                    g.classList.add('is-biztos');
                    g.textContent = 'Biztosan törlöd az összes importált sort? (A CRM-ben rögzítettek maradnak.)';

                    return;
                }

                g.disabled = true;
                kuld('import_visszavon', {}).then(function () {
                    kesz = true;
                    toast('Az importált adatok törölve.', 'siker');
                    d.close();
                }).catch(function (h) { g.disabled = false; toast(h.message, 'hiba'); });
            });
        }

        d.addEventListener('close', function () {
            if (kesz) {
                indulo.van_adat = true;
                frissitAktiv();
            }
        });
    }

    /* ---------------- Kattintások az oldalon ---------------- */

    gyoker.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;
        var g;

        if ((g = cel.closest('[data-pt-ful]'))) {
            var lap = g.getAttribute('data-pt-ful');

            lapra(lap);

            return;
        }

        if ((g = cel.closest('[data-pt-uj]'))) {
            ujTetel(g.getAttribute('data-pt-uj'), S.lap === 'kp' && S.kp.datum !== S.ma ? { datum: S.kp.datum } : {});

            return;
        }

        if ((g = cel.closest('[data-pt-uj-napra]'))) {
            ujTetel('bevetel', { datum: g.getAttribute('data-pt-uj-napra') });

            return;
        }

        if (cel.closest('[data-pt-import]')) {
            importAblak();

            return;
        }

        if ((g = cel.closest('[data-pt-gyors-megis]'))) {
            gyorsMent(g.closest('form'), true);

            return;
        }

        if ((g = cel.closest('[data-pt-munkalap]'))) {
            esemeny.stopPropagation();
            app().nyit('munkalapok', g.getAttribute('data-pt-munkalap'), {});

            return;
        }

        if ((g = cel.closest('[data-pt-naplo-ugras]'))) {
            esemeny.stopPropagation();
            S.naplo.szuro = '';
            lapra('naplo', { ugras: g.getAttribute('data-pt-naplo-ugras'), kiemel: +(g.getAttribute('data-pt-tetel-id') || 0) });

            return;
        }

        if ((g = cel.closest('[data-pt-tetel]'))) {
            if (!cel.closest('button')) {
                tetelNyit(g.getAttribute('data-pt-tetel'));
            }

            return;
        }

        if ((g = cel.closest('[data-pt-kp-nap]'))) {
            lapra('kp', g.getAttribute('data-pt-kp-nap'));

            return;
        }

        if ((g = cel.closest('[data-pt-kp-lep]'))) {
            lapra('kp', napEltol(S.kp.datum, +g.getAttribute('data-pt-kp-lep')));

            return;
        }

        if ((g = cel.closest('[data-pt-cimlet-lep]'))) {
            var c = g.getAttribute('data-pt-cimlet-lep');

            kpBeir(c, (parseInt(S.kp.db[c], 10) || 0) + +g.getAttribute('data-irany'));

            return;
        }

        if ((g = cel.closest('[data-pt-zar]'))) {
            kpMent(g.getAttribute('data-pt-zar') === '1', g);

            return;
        }

        if ((g = cel.closest('[data-pt-ujranyit]'))) {
            if (!g.classList.contains('is-biztos')) {
                g.classList.add('is-biztos');
                g.textContent = 'Biztosan újranyitod?';

                return;
            }

            kuld('ujranyit', { datum: g.getAttribute('data-pt-ujranyit') }).then(function (v) {
                S.lezaratlan = v.lezaratlan || S.lezaratlan;
                toast('A nap újra nyitva – a változások naplózódnak.');

                if (S.lap === 'kp') {
                    S.kp.csomag = v;
                    kpRajzol();
                } else {
                    maRajzol(v);
                }
            }).catch(function (h) { toast(h.message, 'hiba'); });

            return;
        }

        if ((g = cel.closest('[data-pt-egyeztet]'))) {
            lapra('ugynok', g.getAttribute('data-pt-egyeztet'));

            return;
        }

        if (cel.closest('[data-pt-egyeztet-most]')) {
            S.ugynok.datum = gyoker.querySelector('[data-pt-ugynok-datum]').value || S.ma;
            egyeztetesTolt();

            return;
        }

        if ((g = cel.closest('[data-pt-javaslat]'))) {
            var reszek = g.getAttribute('data-pt-javaslat').split(':');
            var tarto = g.closest('[data-pt-egyeztetes], [data-pt-kp-egyeztetes]');
            var v = tarto && tarto.sdhEgyeztetes;
            var j = v && v.megallapitasok[+reszek[0]] && v.megallapitasok[+reszek[0]].javaslatok[+reszek[1]];

            if (j) {
                javaslatVegrehajt(j, g);
            }

            return;
        }

        if ((g = cel.closest('[data-pt-ugy-felvesz]'))) {
            var ugyek = gyoker.querySelector('[data-pt-ugyek]').sdhUgyek;
            var m = ugyek && ugyek.hianyzo[+g.getAttribute('data-pt-ugy-felvesz')];

            if (m) {
                ujTetel('bevetel', { datum: m.datum, leiras: m.leiras, nev: m.nev, osszeg: Math.round(m.hiany), mod: m.mod, ml: m.szam, szamla: m.szamla });
            }

            return;
        }

        if ((g = cel.closest('[data-pt-felvesz-ml]'))) {
            ujTetel('bevetel', { ml: g.getAttribute('data-pt-felvesz-ml'), osszeg: g.getAttribute('data-osszeg'), mod: g.getAttribute('data-mod') });

            return;
        }

        if ((g = cel.closest('[data-pt-szamla-mind]'))) {
            var u = gyoker.querySelector('[data-pt-ugyek]').sdhUgyek;

            g.disabled = true;

            var sorban = (u.szamlaszam || []).reduce(function (p, s) {
                return p.then(function () { return kuld('javit', { muvelet: 'szamla', id: s.id, ertek: s.szamla }); });
            }, Promise.resolve());

            sorban.then(function () {
                toast('A számlaszámok beírva.', 'siker');
                ugyekTolt();
            }).catch(function (h) { g.disabled = false; toast(h.message, 'hiba'); });

            return;
        }

        if ((g = cel.closest('[data-pt-valtozasok]'))) {
            valtozasokAblak(g.getAttribute('data-pt-valtozasok'));

            return;
        }

        if ((g = cel.closest('[data-pt-lapoz]'))) {
            var hova = g.getAttribute('data-pt-lapoz');
            var irany = +g.getAttribute('data-irany');

            if (hova === 'naplo') {
                S.naplo.oldal = Math.max(1, S.naplo.oldal + irany);
                S.naplo.kiemel = 0;
                naploTolt();
                lapElem('naplo').scrollIntoView({ block: 'start' });
            } else if (hova === 'kereses') {
                S.kereses.oldal = Math.max(1, S.kereses.oldal + irany);
                keresesTolt();
            } else if (hova === 'riport') {
                S.riport.oldal = Math.max(1, S.riport.oldal + irany);
                riportNapok();
            }

            return;
        }

        if ((g = cel.closest('[data-pt-szuro]'))) {
            S.naplo.szuro = g.getAttribute('data-pt-szuro');
            S.naplo.oldal = 1;
            naploTolt();

            return;
        }

        if ((g = cel.closest('[data-pt-elore]'))) {
            var sav = g.closest('[data-pt-idoszak]');
            var cel2 = sav.getAttribute('data-pt-idoszak') === 'riport' ? S.riport : S.stat;

            cel2.elore = g.getAttribute('data-pt-elore');

            if (cel2.elore === 'egyeni') {
                Array.prototype.forEach.call(sav.querySelectorAll('[data-pt-elore]'), function (p) { p.classList.toggle('is-aktiv', p === g); });
                sav.querySelector('.sdh-pt-idoszak__egyeni').hidden = false;

                return;
            }

            (sav.getAttribute('data-pt-idoszak') === 'riport' ? riportTolt : statTolt)();

            return;
        }

        if ((g = cel.closest('[data-pt-bontas-ertek]'))) {
            S.stat.bontas = g.getAttribute('data-pt-bontas-ertek');
            statTolt();

            return;
        }

        if ((g = cel.closest('[data-pt-export]'))) {
            var tartomany = g.getAttribute('data-pt-export').split('|');

            exportLetolt(tartomany[0], tartomany[1]);

            return;
        }

        if (cel.closest('[data-pt-export-naplo]')) {
            var napok = (S.naplo.adat && S.naplo.adat.napok) || [];

            if (napok.length) {
                exportLetolt(napok[napok.length - 1].nap.datum, napok[0].nap.datum);
            }

            return;
        }

        if (cel.closest('[data-pt-nyomtat]')) {
            window.print();

            return;
        }

        if ((g = cel.closest('[data-pt-kereses-szuro]'))) {
            S.kereses = { q: '', oldal: 1, szuro: { tol: g.getAttribute('data-tol'), ig: g.getAttribute('data-ig') } };
            S.kereses.szuro[g.getAttribute('data-pt-kereses-szuro')] = '1';
            lapra('kereses');

            return;
        }

        if (cel.closest('[data-pt-szuro-torol]')) {
            S.kereses.szuro = {};
            S.kereses.oldal = 1;
            keresesTolt();

            return;
        }

        if ((g = cel.closest('[data-pt-kerdes-ezt]'))) {
            lapra('ugynok');
            var mezo = gyoker.querySelector('[data-pt-kerdes] input');

            mezo.value = g.getAttribute('data-pt-kerdes-ezt');
            kerdez(mezo.closest('form'));
        }
    });

    function exportLetolt(tol, ig) {
        var url = new URL(B.ajax, window.location.origin);

        url.searchParams.set('action', 'sdh_muhely_penztar_export');
        url.searchParams.set('_wpnonce', B.nonce || '');
        url.searchParams.set('tol', tol);
        url.searchParams.set('ig', ig);
        window.location.href = url.toString();
    }

    gyoker.addEventListener('keydown', function (esemeny) {
        if (esemeny.key === 'Enter' && esemeny.target.matches('[data-pt-tetel]')) {
            tetelNyit(esemeny.target.getAttribute('data-pt-tetel'));
        }
    });

    // A dátummezők (app.js naptára) változása.
    gyoker.addEventListener('change', function (esemeny) {
        var m = esemeny.target;

        if (m.matches('[data-pt-naplo-datum]') && m.value) {
            lapra('naplo', { ugras: m.value });
        } else if (m.matches('[data-pt-kp-datum]') && m.value) {
            lapra('kp', m.value > S.ma ? S.ma : m.value);
        } else if (m.matches('[data-pt-ugynok-datum]') && m.value) {
            S.ugynok.datum = m.value;
            egyeztetesTolt();
        } else if (m.matches('[data-pt-tol], [data-pt-ig]')) {
            var sav = m.closest('[data-pt-idoszak]');
            var cel = sav.getAttribute('data-pt-idoszak') === 'riport' ? S.riport : S.stat;

            cel.elore = 'egyeni';
            cel.tol = sav.querySelector('[data-pt-tol]').value;
            cel.ig = sav.querySelector('[data-pt-ig]').value;

            if (cel.tol && cel.ig) {
                (sav.getAttribute('data-pt-idoszak') === 'riport' ? riportTolt : statTolt)();
            }
        } else if (m.closest('[data-pt-szurok]') && m.closest('.sdh-pt-pillek')) {
            Array.prototype.forEach.call(m.closest('.sdh-pt-pillek').querySelectorAll('.sdh-pt-pill'), function (p) {
                p.classList.toggle('is-aktiv', p.querySelector('input').checked);
            });
        }
    });

    lapElem('kereses').addEventListener('submit', function (esemeny) {
        var f = esemeny.target;

        if (!f.matches('[data-pt-szurok]')) {
            return;
        }

        esemeny.preventDefault();
        esemeny.stopPropagation();

        var sz = {};

        sz.tol = f.querySelector('[name="tol"]').value;
        sz.ig = f.querySelector('[name="ig"]').value;
        sz.min = f.querySelector('[name="min"]').value;
        sz.max = f.querySelector('[name="max"]').value;
        sz.tipus = Array.prototype.map.call(f.querySelectorAll('[name="tipus"]:checked'), function (x) { return x.value; });
        sz.mod = (f.querySelector('[name="mod"]:checked') || {}).value || '';

        ['szamla_nelkul', 'ml_nelkul', 'torolt'].forEach(function (k) {
            if (f.querySelector('[name="' + k + '"]').checked) {
                sz[k] = '1';
            }
        });

        Object.keys(sz).forEach(function (k) {
            if (sz[k] === '' || (Array.isArray(sz[k]) && !sz[k].length)) {
                delete sz[k];
            }
        });

        S.kereses = { q: f.querySelector('[name="q"]').value.trim(), oldal: 1, szuro: sz };
        keresesTolt();
    });

    lapElem('ugynok').addEventListener('submit', function (esemeny) {
        if (esemeny.target.matches('[data-pt-kerdes]')) {
            esemeny.preventDefault();
            esemeny.stopPropagation();
            kerdez(esemeny.target);
        }
    });

    function kerdez(f) {
        var q = f.querySelector('input').value.trim();
        var hely = lapElem('ugynok').querySelector('[data-pt-valasz]');

        if (!q) {
            return;
        }

        hely.innerHTML = '<div class="sdh-pt-toltes">Az ügynök keres…</div>';

        kuld('kerdes', { q: q, datum: S.ugynok.datum }).then(function (v) {
            hely.innerHTML = '<div class="sdh-pt-valasz"><span class="sdh-pt-valasz__forras">' + (v.forras === 'ai' ? 'Claude AI' : 'Helyi keresés') + '</span>' +
                v.valasz.map(function (s) { return '<p>' + e(s) + '</p>'; }).join('') + '</div>';
        }).catch(function (h) {
            hely.innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">' + e(h.message) + '</div>';
        });
    }

    // Új nap / más gépen rögzített tétel: visszatéréskor és percenként frissül a Ma lap.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && S.lap === 'ma') {
            maFrissit();
        }
    });

    window.setInterval(function () {
        if (!document.hidden && S.lap === 'ma' && !document.querySelector('dialog[open]') && !(document.activeElement && document.activeElement.closest('[data-pt-gyors]'))) {
            maFrissit();
        }
    }, 60000);

    // Indulás: a legutóbbi lap (a Keresés nem).
    var mentett = tarol('sdh-pt-lap');

    lapra(mentett && mentett !== 'kereses' ? mentett : 'ma');
}());
