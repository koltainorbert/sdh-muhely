/**
 * SMS (0.57): a „Küldjek SMS-t?” popup státuszváltás után, az Új SMS ablak, a sablonszerkesztő
 * és az SMS menüpont kis kezelői (szabályok mentése, „Felhívtam”).
 *
 * A popupot a szerver „döntésként” tárolja (sms_dontes). Ez a fájl csak megkérdezi, van-e friss
 * nyitott döntésem, és ha igen, megnyitja az űrlapját az app.js popupjában. Státuszváltás után a
 * meglévő `sdh:allapot` / `sdh:mentve` események indítják a lekérdezést – a régi gombokhoz nem
 * kellett nyúlni.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};
    var APP = window.SDH_MUHELY_APP || null;
    var TAROLO = 'sdh-sms-mutatott';

    function ajaxCim() {
        return new URL(B.ajax, window.location.origin);
    }

    function minden(valaszto, gyoker) {
        return Array.prototype.slice.call((gyoker || document).querySelectorAll(valaszto));
    }

    /* ---------------------------------------------------------------- */
    /* Szegmensszámláló – ugyanaz a szabály, mint a szerveren            */
    /* ---------------------------------------------------------------- */

    var GSM = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    var GSM_BOVIT = '^{}\\[~]|€\f';

    function szegmensek(szoveg) {
        szoveg = String(szoveg || '').replace(/\r\n?/g, '\n');

        var gsm = true;
        var hossz = 0;
        var i;

        for (i = 0; i < szoveg.length; i++) {
            var k = szoveg.charAt(i);

            if (GSM.indexOf(k) !== -1) {
                hossz += 1;
            } else if (GSM_BOVIT.indexOf(k) !== -1) {
                hossz += 2;
            } else {
                gsm = false;
                break;
            }
        }

        var egy = gsm ? 160 : 70;
        var tobb = gsm ? 153 : 67;

        if (!gsm) {
            hossz = szoveg.length; // UTF-16 kódegység = az UCS-2 hossza
        }

        var db = hossz === 0 ? 0 : (hossz <= egy ? 1 : Math.ceil(hossz / tobb));

        return { kodolas: gsm ? 'GSM-7' : 'UCS-2', hossz: hossz, szegmens: db, egy: egy, tobb: tobb };
    }

    function szamlalo(mezo) {
        var doboz = mezo.parentNode && mezo.parentNode.querySelector('[data-sdh-sms-szaml]');

        if (!doboz) {
            return;
        }

        var sz = szegmensek(mezo.value);

        doboz.textContent = sz.hossz + ' karakter · ' + sz.szegmens + ' SMS (' + sz.kodolas + ')' +
            (sz.kodolas === 'UCS-2' ? ' – az ékezetes betűk miatt egy SMS-be csak ' + sz.egy + ' karakter fér' : '');
        doboz.classList.toggle('is-sok', sz.szegmens > 3);
    }

    document.addEventListener('input', function (esemeny) {
        var m = esemeny.target;

        if (m && m.matches && m.matches('[data-sdh-sms-szoveg]')) {
            szamlalo(m);
        }
    });

    document.addEventListener('sdh:urlap-betoltve', function (esemeny) {
        var torzs = esemeny.detail && esemeny.detail.torzs;

        if (torzs) {
            minden('[data-sdh-sms-szoveg]', torzs).forEach(szamlalo);
        }
    });

    /* ---------------------------------------------------------------- */
    /* Sablonváltás, változó beszúrása, válaszgombok                     */
    /* ---------------------------------------------------------------- */

    function post(muvelet, adat) {
        var f = new FormData();

        f.set('action', muvelet);
        f.set('_wpnonce', B.nonce || '');
        Object.keys(adat || {}).forEach(function (k) { f.set(k, adat[k]); });

        return fetch(ajaxCim().toString(), { method: 'POST', body: f, credentials: 'same-origin' })
            .then(function (v) { return v.json(); });
    }

    document.addEventListener('change', function (esemeny) {
        var sel = esemeny.target;

        if (!sel || !sel.matches || !sel.matches('[data-sdh-sms-sablon]')) {
            return;
        }

        var urlap = sel.closest('form');
        var mezo = urlap && urlap.querySelector('[data-sdh-sms-szoveg]');
        var figy = urlap && urlap.querySelector('[data-sdh-sms-hianyzik]');

        if (!mezo || sel.value === '0') {
            return;
        }

        post('sdh_muhely_sms_elonezet', {
            sablon_id: sel.value,
            munkalap_id: sel.dataset.munkalap || '0',
            eszkoz_id: sel.dataset.eszkoz || '0',
            ugyfel_id: sel.dataset.ugyfel || '0',
            terulet: sel.dataset.terulet || 'lap',
            allapot: sel.dataset.allapot || ''
        }).then(function (v) {
            if (!v || !v.success) {
                return;
            }

            mezo.value = v.data.szoveg;
            szamlalo(mezo);

            if (figy) {
                figy.textContent = v.data.uzenet || '';
                figy.hidden = !v.data.uzenet;
            }
        });
    });

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;

        if (!cel || !cel.closest) {
            return;
        }

        // Változó beszúrása a kurzorhoz.
        var valtozo = cel.closest('[data-sdh-sms-valtozo]');

        if (valtozo) {
            var urlap = valtozo.closest('form');
            var mezo = urlap && urlap.querySelector('[data-sdh-sms-szoveg]');

            if (mezo) {
                var elejen = mezo.selectionStart || 0;
                var vegen = mezo.selectionEnd || elejen;
                var szo = valtozo.getAttribute('data-sdh-sms-valtozo');

                mezo.value = mezo.value.slice(0, elejen) + szo + mezo.value.slice(vegen);
                mezo.focus();
                mezo.setSelectionRange(elejen + szo.length, elejen + szo.length);
                szamlalo(mezo);
            }

            return;
        }

        // A döntés gombjai: melyik válasz megy a szervernek.
        var gomb = cel.closest('[data-sdh-sms-gomb]');

        if (gomb) {
            var f = gomb.closest('form');
            var rejtett = f && f.querySelector('[data-sdh-sms-valasz]');

            if (rejtett) {
                rejtett.value = gomb.getAttribute('data-sdh-sms-gomb');
            }

            return;
        }

        // Új webhook-kulcs (csak admin).
        var titok = cel.closest('[data-sdh-sms-titok]');

        if (titok) {
            if (!window.confirm('Új kulcs után a régi webhook-cím nem működik – a telefonban át kell írni. Folytatod?')) {
                return;
            }

            post('sdh_muhely_sms_webhook_titok', {}).then(function (v) {
                var mezo = document.getElementById('sdh-sms-webhook-url');

                if (v && v.success && mezo) {
                    mezo.value = v.data.url;
                    toast('Új kulcs kész. Írd át a telefon appjában is.', false);
                } else {
                    toast((v && v.data && v.data.uzenet) || 'Nem sikerült.', true);
                }
            }).catch(function () { toast('Nem sikerült.', true); });

            return;
        }

        // „Felhívtam”
        var hivott = cel.closest('[data-sdh-sms-hivott]');

        if (hivott) {
            hivott.disabled = true;

            post('sdh_muhely_sms_hivott', { id: hivott.getAttribute('data-sdh-sms-hivott') }).then(function (v) {
                if (v && v.success) {
                    var sor = hivott.closest('tr');

                    if (sor) {
                        sor.remove();
                    }

                    var jel = document.querySelector('[data-sdh-jelveny="sms"]');

                    if (jel && v.data && typeof v.data.hivando !== 'undefined') {
                        jel.textContent = String(v.data.hivando);
                    }
                } else {
                    hivott.disabled = false;
                    toast((v && v.data && v.data.uzenet) || 'Nem sikerült rögzíteni.', true);
                }
            }).catch(function () {
                hivott.disabled = false;
                toast('Nem sikerült rögzíteni.', true);
            });

            return;
        }

        // Új SMS gomb más felületeken (pl. az RMA csevegőben).
        var uj = cel.closest('[data-sdh-sms-uj]');

        if (uj && APP) {
            esemeny.preventDefault();
            megnyit('smsuj', 0, {
                munkalap_id: uj.getAttribute('data-munkalap') || '0',
                ugyfel_id: uj.getAttribute('data-ugyfel') || '0',
                eszkoz_id: uj.getAttribute('data-eszkoz') || '0'
            });
        }
    });

    /* ---------------------------------------------------------------- */
    /* Szabályok mentése                                                 */
    /* ---------------------------------------------------------------- */

    document.addEventListener('submit', function (esemeny) {
        var urlap = esemeny.target;

        if (!urlap || !urlap.matches || !urlap.matches('[data-sdh-sms-parosit]')) {
            return;
        }

        esemeny.preventDefault();

        var mezo = urlap.querySelector('[name="munkalap_szam"]');

        post('sdh_muhely_sms_parosit', {
            id: urlap.getAttribute('data-sdh-sms-parosit'),
            munkalap_szam: mezo ? mezo.value : ''
        }).then(function (v) {
            if (v && v.success) {
                window.location.reload();
            } else {
                toast((v && v.data && v.data.uzenet) || 'Nem sikerült hozzárendelni.', true);
            }
        }).catch(function () { toast('Nem sikerült hozzárendelni.', true); });
    });

    document.addEventListener('submit', function (esemeny) {
        var urlap = esemeny.target;

        if (!urlap || !urlap.matches || !urlap.matches('[data-sdh-sms-szabalyok]')) {
            return;
        }

        esemeny.preventDefault();

        var uzenet = urlap.querySelector('[data-sdh-sms-uzenet]');
        var f = new FormData(urlap);

        f.set('action', 'sdh_muhely_sms_szabaly_ment');

        if (uzenet) {
            uzenet.textContent = 'Mentés…';
            uzenet.classList.remove('is-hiba');
        }

        fetch(ajaxCim().toString(), { method: 'POST', body: f, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                if (uzenet) {
                    uzenet.textContent = (v && v.data && v.data.uzenet) || (v && v.success ? 'Elmentve.' : 'A mentés nem sikerült.');
                    uzenet.classList.toggle('is-hiba', !(v && v.success));
                }
            })
            .catch(function () {
                if (uzenet) {
                    uzenet.textContent = 'A mentés nem sikerült.';
                    uzenet.classList.add('is-hiba');
                }
            });
    });

    /* ---------------------------------------------------------------- */
    /* Kis értesítés                                                     */
    /* ---------------------------------------------------------------- */

    function toast(szoveg, hiba) {
        var d = document.createElement('div');

        d.className = 'sdh-sms-toast' + (hiba ? ' is-hiba' : '');
        d.setAttribute('role', 'status');
        d.textContent = szoveg;
        document.body.appendChild(d);

        window.setTimeout(function () { d.classList.add('is-el'); }, 3800);
        window.setTimeout(function () { d.remove(); }, 4400);
    }

    /* ---------------------------------------------------------------- */
    /* Popup megnyitása (ráépülő szinten, ha már van nyitott ablak)       */
    /* ---------------------------------------------------------------- */

    function megnyit(modul, id, parameterek) {
        if (!APP) {
            return;
        }

        var szint = document.querySelector('dialog.sdh-modal[open]') ? 1 : 0;

        APP.nyit(modul, id, {
            szint: szint,
            parameterek: parameterek || {},
            siker: function (adat) {
                toast((adat && adat.uzenet) || 'Rendben.');
                document.dispatchEvent(new CustomEvent('sdh:sms-valtozott', { detail: adat || {} }));
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* A „Küldjek SMS-t?” kérdés megjelenítése                           */
    /* ---------------------------------------------------------------- */

    function mutatottak() {
        try {
            return JSON.parse(window.sessionStorage.getItem(TAROLO) || '[]');
        } catch (hiba) {
            return [];
        }
    }

    function mutatott(id) {
        var l = mutatottak();

        l.push(id);

        try {
            window.sessionStorage.setItem(TAROLO, JSON.stringify(l.slice(-50)));
        } catch (hiba) { /* privát ablak: oldalváltásig él */ }
    }

    var kerFut = false;

    function dontesKer() {
        if (kerFut || !B.sms || !B.sms.be || !APP) {
            return;
        }

        kerFut = true;

        var cim = ajaxCim();

        cim.searchParams.set('action', 'sdh_muhely_sms_dontesek');
        cim.searchParams.set('_wpnonce', B.nonce || '');

        fetch(cim.toString(), { credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                var id = v && v.success && v.data ? parseInt(v.data.friss, 10) || 0 : 0;

                if (id > 0 && mutatottak().indexOf(id) === -1) {
                    mutatott(id);
                    window.setTimeout(function () { megnyit('smsdontes', id, {}); }, 350);
                }
            })
            .catch(function () { /* kényelmi szolgáltatás: hiba esetén a döntés függőben marad */ })
            .then(function () { kerFut = false; });
    }

    ['sdh:allapot', 'sdh:mentve'].forEach(function (nev) {
        document.addEventListener(nev, function () {
            window.setTimeout(dontesKer, 250);
        });
    });

    // Oldalbetöltéskor (pl. teljes oldalas mentés után) is megnézzük, ha van mit.
    function indul() {
        if (B.sms && B.sms.be && B.sms.fuggo > 0) {
            dontesKer();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', indul);
    } else {
        indul();
    }

    window.SDH_SMS = { szegmensek: szegmensek, megnyit: megnyit, dontesKer: dontesKer };
}());
