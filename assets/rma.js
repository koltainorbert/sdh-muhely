/**
 * SDH Műhely – RMA a CRM-ben (0.27).
 *
 *  - Üzenet küldése az ügyfélnek a munkalap RMA lapfüléről (munkalap-ablak
 *    és Áttekintés). Űrlapon belül áll, ezért nincs <form>: gomb / Ctrl+Enter.
 *  - Lapozás görgetősáv helyett ([data-sdh-lapoz="N"]): a popupban nincs
 *    görgetősáv, a hosszú üzenetszál és történet lapozható.
 *  - Az Áttekintés részletpaneljén az „RMA / Üzenetek" fül a munkalap-ablakot
 *    nyitja meg az RMA lapfülön (popupban, mint a munkalap).
 *  - Olvasatlan-jelzés: az oldalmenü „Üzenetek" jelvénye percenként frissül;
 *    a lapfül megnyitásakor a bejövő üzenetek olvasottra állnak.
 *
 * Eseménydelegálással dolgozik: a részletpanel és a popup tartalma AJAX-szal
 * cserélődik.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};
    var FRISSITES_MS = 45000;
    var eredetiCim = document.title.replace(/^\(\d+\)\s*/, '');

    function kuld(akcio, mezok) {
        var adat = new FormData();

        adat.set('action', akcio);
        adat.set('_wpnonce', B.nonce || '');
        adat.set('kontextus', B.kontextus || 'admin');

        Object.keys(mezok || {}).forEach(function (nev) {
            adat.set(nev, mezok[nev]);
        });

        return fetch(new URL(B.ajax, window.location.origin).toString(), {
            method: 'POST',
            body: adat,
            credentials: 'same-origin'
        })
            .then(function (valasz) {
                return valasz.json().catch(function () {
                    throw new Error('A szerver nem várt választ adott (' + valasz.status + ').');
                });
            })
            .then(function (json) {
                if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.uzenet) || 'A művelet nem sikerült.');
                }

                return json.data;
            });
    }

    function minden(szelektor, gyoker) {
        return Array.prototype.slice.call((gyoker || document).querySelectorAll(szelektor));
    }

    /* ---------------------------------------------------------------- */
    /* Jelvények                                                         */
    /* ---------------------------------------------------------------- */

    /** Az oldalmenü (és a wp-admin menü) olvasatlan-jelvénye. */
    function menuJelveny(db) {
        db = parseInt(db, 10) || 0;

        minden('[data-sdh-jelveny="uzenetek"]').forEach(function (jel) {
            var elozo = parseInt(jel.textContent, 10) || 0;

            jel.textContent = String(db);
            jel.hidden = db <= 0;

            if (db > elozo) {
                jel.classList.remove('is-friss');
                void jel.offsetWidth; // az animáció újraindításához
                jel.classList.add('is-friss');
            }
        });

        minden('#adminmenu .toplevel_page_sdh-muhely .awaiting-mod').forEach(function (jel) {
            jel.className = 'awaiting-mod count-' + db;
            jel.innerHTML = '<span class="pending-count">' + db + '</span>';
            jel.style.display = db > 0 ? '' : 'none';
        });

        document.title = db > 0 ? '(' + db + ') ' + eredetiCim : eredetiCim;
    }

    /** Egy munkalap RMA-jelvényei (popup-lapfül, Áttekintés lapfül, postafiók-sor). */
    function lapJelveny(id, szamlalo) {
        if (!szamlalo) {
            return;
        }

        var jelvenyek = [];

        minden('[data-sdh-rma="' + id + '"]').forEach(function (rma) {
            var fulek = rma.closest('.sdh-fulek');

            if (fulek) {
                jelvenyek = jelvenyek.concat(minden(':scope > .sdh-fulek__sav [data-sdh-db="rma"]', fulek));
            }

            if (rma.closest('.sdh-reszlet')) {
                jelvenyek = jelvenyek.concat(minden('.sdh-reszlet__ful[data-ful="rma"] [data-sdh-db="rma"]'));
            }
        });

        jelvenyek.forEach(function (jel) {
            jel.textContent = String(szamlalo.osszes);
            jel.hidden = szamlalo.osszes <= 0;
            jel.classList.toggle('is-uj', szamlalo.uj > 0);
        });

        minden('[data-sdh-posta="' + id + '"]').forEach(function (sor) {
            sor.classList.toggle('is-uj', szamlalo.uj > 0);

            var db = sor.querySelector('.sdh-fulek__db');

            if (db) {
                db.classList.toggle('is-uj', szamlalo.uj > 0);
                db.textContent = szamlalo.uj > 0 ? szamlalo.uj + ' új' : String(szamlalo.osszes);
            }
        });
    }

    /** A munkalap bejövő üzeneteinek olvasottra állítása, ha van olvasatlan. */
    function olvasottra(gyoker, id) {
        if (!id || !gyoker || !gyoker.querySelector('[data-sdh-db="rma"].is-uj, .sdh-uz__uj')) {
            return;
        }

        kuld('sdh_muhely_uzenet_olvasva', { id: id })
            .then(function (adat) {
                minden('[data-sdh-rma="' + id + '"] .sdh-uz__uj').forEach(function (jel) {
                    jel.remove();
                });
                lapJelveny(id, adat.szamlalo);
                menuJelveny(adat.olvasatlan);
            })
            .catch(function () {});
    }

    function frissit() {
        if (document.visibilityState === 'hidden' || !B.ajax) {
            return;
        }

        kuld('sdh_muhely_uzenet_allapot', {})
            .then(function (adat) { menuJelveny(adat.olvasatlan); })
            .catch(function () {});
    }

    if (B.ajax) {
        setInterval(frissit, FRISSITES_MS);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                frissit();
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Lapozás görgetősáv helyett                                        */
    /* ---------------------------------------------------------------- */

    function lapoz(lista) {
        var meret = parseInt(lista.getAttribute('data-sdh-lapoz'), 10) || 5;
        var elemek = Array.prototype.filter.call(lista.children, function (elem) {
            return !elem.classList.contains('sdh-uz-ures');
        });
        var lapozo = lista.nextElementSibling && lista.nextElementSibling.classList.contains('sdh-lapozo')
            ? lista.nextElementSibling
            : null;

        if (elemek.length <= meret) {
            elemek.forEach(function (elem) { elem.removeAttribute('data-sdh-rejtett'); });

            if (lapozo) {
                lapozo.remove();
            }

            return;
        }

        var lapok = Math.ceil(elemek.length / meret);
        var lap = Math.max(0, Math.min(parseInt(lista.getAttribute('data-sdh-lap') || '0', 10), lapok - 1));

        lista.setAttribute('data-sdh-lap', String(lap));

        elemek.forEach(function (elem, i) {
            if (i >= lap * meret && i < (lap + 1) * meret) {
                elem.removeAttribute('data-sdh-rejtett');
            } else {
                elem.setAttribute('data-sdh-rejtett', '');
            }
        });

        if (!lapozo) {
            lapozo = document.createElement('div');
            lapozo.className = 'sdh-lapozo';
            lista.parentNode.insertBefore(lapozo, lista.nextSibling);
        }

        lapozo.innerHTML =
            '<button type="button" data-sdh-lapoz-irany="-1" aria-label="Előző oldal"' + (lap === 0 ? ' disabled' : '') + '>‹</button>' +
            '<span>' + (lap + 1) + ' / ' + lapok + '</span>' +
            '<button type="button" data-sdh-lapoz-irany="1" aria-label="Következő oldal"' + (lap >= lapok - 1 ? ' disabled' : '') + '>›</button>';
    }

    function lapozIndit(gyoker) {
        if (gyoker.matches && gyoker.matches('[data-sdh-lapoz]')) {
            lapoz(gyoker);
        }

        minden('[data-sdh-lapoz]', gyoker).forEach(lapoz);
    }

    if (window.MutationObserver) {
        new MutationObserver(function (valtozasok) {
            valtozasok.forEach(function (valtozas) {
                Array.prototype.forEach.call(valtozas.addedNodes, function (elem) {
                    if (elem.nodeType === 1 && !elem.classList.contains('sdh-lapozo')) {
                        lapozIndit(elem);
                    }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    lapozIndit(document);

    /* ---------------------------------------------------------------- */
    /* Üzenet küldése                                                    */
    /* ---------------------------------------------------------------- */

    function kuldes(urlap) {
        var mezo = urlap.querySelector('[data-sdh-uzenet-szoveg]');
        var email = urlap.querySelector('[data-sdh-uzenet-email]');
        var gomb = urlap.querySelector('[data-sdh-uzenet-kuld]');
        var hiba = urlap.querySelector('[data-sdh-uzenet-hiba]');
        var szoveg = (mezo.value || '').trim();
        var id = urlap.getAttribute('data-id');

        if (!szoveg) {
            mezo.focus();
            return;
        }

        gomb.disabled = true;
        hiba.hidden = true;

        kuld('sdh_muhely_uzenet_kuld', {
            id: id,
            szoveg: szoveg,
            email: email && email.checked && !email.disabled ? '1' : ''
        })
            .then(function (adat) {
                var rma = urlap.closest('[data-sdh-rma]');
                var szal = rma && rma.querySelector('[data-sdh-uzenetek]');

                if (szal) {
                    szal.innerHTML = adat.html; // a szerveren szűrt HTML
                    szal.setAttribute('data-sdh-lap', '0');
                    lapoz(szal);
                }

                mezo.value = '';
                lapJelveny(id, adat.szamlalo);
                menuJelveny(adat.olvasatlan);

                if (email && email.checked && !email.disabled && !adat.email) {
                    hiba.textContent = 'Az üzenet elmentve, de az e-mail nem ment el (ellenőrizd a levelezés beállítását).';
                    hiba.hidden = false;
                }
            })
            .catch(function (ok) {
                hiba.textContent = ok.message;
                hiba.hidden = false;
            })
            .then(function () {
                gomb.disabled = false;
            });
    }

    document.addEventListener('keydown', function (esemeny) {
        if (esemeny.key !== 'Enter' || !(esemeny.ctrlKey || esemeny.metaKey)) {
            return;
        }

        var urlap = esemeny.target.closest && esemeny.target.closest('[data-sdh-uzenet-urlap]');

        if (urlap) {
            esemeny.preventDefault();
            kuldes(urlap);
        }
    });

    /* ---------------------------------------------------------------- */
    /* Kattintások                                                       */
    /* ---------------------------------------------------------------- */

    /**
     * Az Áttekintés „RMA / Üzenetek" füle a munkalap-ablakot nyitja meg az
     * RMA lapfülön. Rögzítő fázisban fut, hogy a rács fülváltója ne kapja meg.
     */
    window.addEventListener('click', function (esemeny) {
        var ful = esemeny.target.closest && esemeny.target.closest('.sdh-reszlet__ful[data-ful="rma"]');

        if (!ful) {
            return;
        }

        var rma = document.querySelector('.sdh-reszlet__panel[data-ful="rma"] [data-sdh-rma]');

        if (!rma) {
            return; // nincs munkalap-ablak: marad a sima fülváltás
        }

        esemeny.preventDefault();
        esemeny.stopPropagation();

        var indito = document.createElement('a');
        indito.href = '#';
        indito.hidden = true;
        indito.setAttribute('data-sdh-urlap', 'munkalapok');
        indito.setAttribute('data-sdh-id', rma.getAttribute('data-sdh-rma'));
        indito.setAttribute('data-sdh-ful', 'rma');
        document.body.appendChild(indito);
        indito.click();
        indito.remove();
    }, true);

    document.addEventListener('click', function (esemeny) {
        var kuldGomb = esemeny.target.closest('[data-sdh-uzenet-kuld]');

        if (kuldGomb) {
            esemeny.preventDefault();
            kuldes(kuldGomb.closest('[data-sdh-uzenet-urlap]'));

            return;
        }

        var lapozGomb = esemeny.target.closest('[data-sdh-lapoz-irany]');

        if (lapozGomb) {
            var lapozo = lapozGomb.closest('.sdh-lapozo');
            var lista = lapozo && lapozo.previousElementSibling;

            if (lista && lista.hasAttribute('data-sdh-lapoz')) {
                var lap = parseInt(lista.getAttribute('data-sdh-lap') || '0', 10) + parseInt(lapozGomb.getAttribute('data-sdh-lapoz-irany'), 10);
                lista.setAttribute('data-sdh-lap', String(Math.max(0, lap)));
                lapoz(lista);
            }

            return;
        }

        var masol = esemeny.target.closest('[data-sdh-rma-masol]');

        if (masol) {
            var szoveg = masol.getAttribute('data-sdh-rma-masol');
            var kesz = function () {
                var eredeti = masol.textContent;

                masol.textContent = 'Másolva';
                setTimeout(function () { masol.textContent = eredeti; }, 1400);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(szoveg).then(kesz, function () { window.prompt('Másold ki:', szoveg); });
            } else {
                window.prompt('Másold ki:', szoveg);
            }

            return;
        }

        // A munkalap-ablak RMA lapfüle: megnyitáskor olvasottra.
        var rmaFul = esemeny.target.closest('label[data-sdh-rma-ful]');

        if (rmaFul) {
            var fulek = rmaFul.closest('.sdh-fulek');
            var rma = fulek && fulek.querySelector('[data-sdh-rma]');

            if (rma) {
                olvasottra(fulek, rma.getAttribute('data-sdh-rma'));
            }
        }
    });

    // A munkalap-ablak az RMA lapfülön nyílt (pl. az Áttekintésből vagy a postafiókból).
    document.addEventListener('sdh:urlap-betoltve', function (esemeny) {
        var d = esemeny.detail || {};

        if (d.ful !== 'rma' || !d.torzs) {
            return;
        }

        var rma = d.torzs.querySelector('[data-sdh-rma]');

        if (rma) {
            olvasottra(d.torzs, rma.getAttribute('data-sdh-rma'));
        }
    });
}());
