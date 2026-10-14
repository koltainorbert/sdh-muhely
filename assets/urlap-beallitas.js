/**
 * Űrlap-beállítások (0.62.0) – a fogaskerék minden űrlapon.
 *
 * - A fogaskerék (data-sdh-ub-nyit) nyitja / zárja a kapcsolók paneljét.
 * - Egy kapcsoló átbillentése élőben elrejti / megmutatja az elemet
 *   (data-sdh-ub="…" → data-sdh-ub-ki), a választható állapotokat pedig a
 *   lap állapotválasztójában.
 * - Mentés: admin-ajax (sdh_muhely_urlap_beall_ment) – utána minden
 *   megnyitáskor így nyílik az űrlap, a bővítmény frissítése után is.
 * - Lapfülek: ha a kiválasztott lapfület elrejtették, az első látható nyílik;
 *   ha egy lapfülsor minden füle rejtett, a sor is eltűnik.
 * - A számla ablakában a „munkalap tételei rajta" kapcsoló (data-sdh-szamla-auto)
 *   EBBEN az ablakban mind be- vagy kipipálja a tételeket.
 *
 * A szerver (SDH_Muhely_Urlap_Beallitas) rajzolja a kezdőállapotot, így
 * nincs villanás; ez a szkript csak a változást és a lapfüleket kezeli.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};

    function minden(gyoker, szelektor) {
        return Array.prototype.slice.call((gyoker || document).querySelectorAll(szelektor));
    }

    /** Az ablak (vagy oldal), amelyben a fogaskerék és az űrlap áll. */
    function hatokor(elem) {
        return elem.closest('.sdh-modal__torzs') || elem.closest('.sdh-modal__doboz') || document;
    }

    /* ---- Lapfülek: a kiválasztott ne legyen rejtett ------------------ */

    function lathato(cimke) {
        return !cimke.hidden && !cimke.hasAttribute('data-sdh-ub-ki');
    }

    function fulJavit(gyoker) {
        minden(gyoker, '.sdh-fulek').forEach(function (fulek) {
            var sav = fulek.querySelector(':scope > .sdh-fulek__sav');

            if (!sav) {
                return;
            }

            var cimkek = minden(sav, ':scope > label[for]');

            // Csak a fogaskerékkel kapcsolható lapfülsorokhoz nyúl (a többi oldal saját logikája marad).
            if (!cimkek.some(function (c) { return c.hasAttribute('data-sdh-ub'); })) {
                return;
            }

            var lathatok = cimkek.filter(lathato);

            // Minden füle rejtett: az egész lapfülsor eltűnik (és visszajön, ha egy is látszik).
            if (cimkek.length && !lathatok.length) {
                if (!fulek.hasAttribute('data-sdh-ub-ki')) {
                    fulek.setAttribute('data-sdh-ub-ki', '');
                    fulek.setAttribute('data-sdh-ub-auto', '');
                }

                return;
            }

            if (fulek.hasAttribute('data-sdh-ub-auto')) {
                fulek.removeAttribute('data-sdh-ub-ki');
                fulek.removeAttribute('data-sdh-ub-auto');
            }

            var kivalasztott = cimkek.filter(function (c) {
                var r = document.getElementById(c.getAttribute('for'));

                return r && r.checked;
            })[0];

            if (lathatok.length && (!kivalasztott || !lathato(kivalasztott))) {
                var radio = document.getElementById(lathatok[0].getAttribute('for'));

                if (radio) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
    }

    /* ---- Élő kapcsolás ----------------------------------------------- */

    function elemKapcsol(gyoker, elem, be) {
        var utolso = null;

        minden(gyoker, '[data-sdh-ub="' + elem.replace(/[^a-z0-9_]/gi, '') + '"]').forEach(function (cel) {
            if (be) {
                cel.removeAttribute('data-sdh-ub-ki');
            } else {
                cel.setAttribute('data-sdh-ub-ki', '');

                // Az elrejtett számlatétel (0.63.0) nem maradhat kipipálva: ami nem látszik, az nem megy a számlára.
                minden(cel, '[data-sdh-szamla-tetel]').concat(cel.matches('[data-sdh-szamla-tetel]') ? [cel] : []).forEach(function (pipa) {
                    pipa.checked = false;
                    utolso = pipa;
                });
            }
        });

        // A számla ablaka újraszámol (végösszeg, „nincs tétel" sor).
        var urlap = gyoker.querySelector ? gyoker.querySelector('[data-sdh-szamlaurlap]') : null;
        var jelzo = utolso || (urlap ? urlap.querySelector('[data-sdh-szamla-tetel]') : null);

        if (jelzo) {
            jelzo.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function allapotKapcsol(gyoker, kulcs, be) {
        minden(gyoker, 'select[name="allapot"] option[data-sdh-ub-allapot]').forEach(function (opcio) {
            if (opcio.getAttribute('data-sdh-ub-allapot') !== kulcs) {
                return;
            }

            // Ami a lapon épp áll, az mindig választható marad.
            var marad = be || opcio.selected;

            opcio.hidden = !marad;
            opcio.disabled = !marad;
        });
    }

    function panelAlkalmaz(panel) {
        var gyoker = hatokor(panel);

        minden(panel, '[data-sdh-ub-elem]').forEach(function (p) {
            elemKapcsol(gyoker, p.getAttribute('data-sdh-ub-elem'), p.checked);
        });

        minden(panel, '[data-sdh-ub-allapot]').forEach(function (p) {
            allapotKapcsol(gyoker, p.getAttribute('data-sdh-ub-allapot'), p.checked);
        });

        fulJavit(gyoker);
    }

    /* ---- Nyitás, zárás ----------------------------------------------- */

    function panelNyit(gomb, nyit) {
        var doboz = gomb.closest('.sdh-ub');
        var panel = doboz ? doboz.querySelector('[data-sdh-ub-panel]') : null;

        if (!panel) {
            return;
        }

        panel.hidden = !nyit;
        gomb.setAttribute('aria-expanded', nyit ? 'true' : 'false');

        if (nyit) {
            var elso = panel.querySelector('input:not([disabled])');

            if (elso) {
                elso.focus({ preventScroll: true });
            }
        }
    }

    function mindZar(kiveve) {
        minden(document, '[data-sdh-ub-nyit][aria-expanded="true"]').forEach(function (g) {
            if (g !== kiveve) {
                panelNyit(g, false);
            }
        });
    }

    /* ---- Mentés ------------------------------------------------------- */

    function uzen(panel, szoveg, tipus) {
        var sor = panel.querySelector('[data-sdh-ub-allapotsor]');

        if (sor) {
            sor.textContent = szoveg;
            sor.setAttribute('data-tipus', tipus || '');
        }
    }

    function ment(panel, gomb) {
        var adat = new FormData();

        adat.append('action', 'sdh_muhely_urlap_beall_ment');
        adat.append('_ajax_nonce', B.nonce || '');
        adat.append('urlap', panel.getAttribute('data-sdh-ub-panel') || '');

        minden(panel, '[data-sdh-ub-elem]').forEach(function (p) {
            if (p.checked) {
                adat.append('be[]', p.value);
            }
        });

        minden(panel, '[data-sdh-ub-allapot]').forEach(function (p) {
            if (p.checked) {
                adat.append('allapot_be[]', p.value);
            }
        });

        gomb.disabled = true;
        uzen(panel, 'Mentés…', '');

        fetch(B.ajax || window.ajaxurl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: adat })
            .then(function (v) {
                return v.json();
            })
            .then(function (valasz) {
                var u = valasz && valasz.data && valasz.data.uzenet ? valasz.data.uzenet : '';

                uzen(panel, valasz && valasz.success ? (u || 'Elmentve.') : (u || 'A mentés nem sikerült.'), valasz && valasz.success ? 'siker' : 'hiba');
            })
            .catch(function () {
                uzen(panel, 'A mentés nem sikerült (nincs kapcsolat).', 'hiba');
            })
            .then(function () {
                gomb.disabled = false;
            });
    }

    /* ---- Számla: a munkalap tételei egy kapcsolóval -------------------- */

    function szamlaAutoKapcsol(kapcsolo) {
        var urlap = kapcsolo.closest('[data-sdh-szamlaurlap]');

        if (!urlap) {
            return;
        }

        // Ha egy eszköz van kiválasztva („Mi kerül a számlára"), csak annak a tételei.
        var eszkoz = urlap.querySelector('[data-sdh-szamla-eszkoz]:checked');
        var csak = eszkoz && eszkoz.value !== 'mind' ? eszkoz.value : '';
        var utolso = null;

        minden(urlap, '[data-sdh-szamla-tetel]').forEach(function (pipa) {
            if (pipa.getAttribute('data-szamlazva') === '1' || pipa.closest('[data-sdh-ub-ki]')) {
                return;
            }

            pipa.checked = kapcsolo.checked && (csak === '' || pipa.getAttribute('data-eszkoz') === csak);
            utolso = pipa;
        });

        // A végösszeg és a gombok frissítése (app.js: az ablak minden változásra újraszámol).
        if (utolso) {
            utolso.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    /* ---- Események ---------------------------------------------------- */

    document.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;
        var nyito = cel.closest ? cel.closest('[data-sdh-ub-nyit]') : null;

        if (nyito) {
            var nyitva = nyito.getAttribute('aria-expanded') === 'true';

            mindZar(nyito);
            panelNyit(nyito, !nyitva);

            return;
        }

        var panel = cel.closest ? cel.closest('[data-sdh-ub-panel]') : null;

        if (panel) {
            var doboz = panel.closest('.sdh-ub');
            var gomb = doboz ? doboz.querySelector('[data-sdh-ub-nyit]') : null;

            if (cel.closest('[data-sdh-ub-ment]')) {
                ment(panel, cel.closest('[data-sdh-ub-ment]'));
            } else if (cel.closest('[data-sdh-ub-alap]')) {
                minden(panel, '[data-sdh-ub-elem], [data-sdh-ub-allapot]').forEach(function (p) {
                    p.checked = true;
                });
                panelAlkalmaz(panel);
                uzen(panel, 'Minden látszik – a Mentéssel marad így.', '');
            } else if (cel.closest('[data-sdh-ub-bezar]') && gomb) {
                panelNyit(gomb, false);
                gomb.focus({ preventScroll: true });
            }

            return;
        }

        // Kattintás máshová: a nyitott panel bezárul.
        mindZar(null);
    });

    document.addEventListener('change', function (esemeny) {
        var cel = esemeny.target;

        if (!cel.matches) {
            return;
        }

        if (cel.matches('[data-sdh-szamla-auto]')) {
            szamlaAutoKapcsol(cel);

            return;
        }

        // Csak a lebegő panel kapcsol élőben; a Beállítások lapfüle a Mentéskor ment.
        var panel = cel.closest('.sdh-ub-panel--lebego');

        if (!panel) {
            return;
        }

        var gyoker = hatokor(panel);

        if (cel.matches('[data-sdh-ub-elem]')) {
            elemKapcsol(gyoker, cel.getAttribute('data-sdh-ub-elem'), cel.checked);
            fulJavit(gyoker);
            uzen(panel, 'Nincs mentve – a Mentés után marad így.', '');
        } else if (cel.matches('[data-sdh-ub-allapot]')) {
            allapotKapcsol(gyoker, cel.getAttribute('data-sdh-ub-allapot'), cel.checked);
            uzen(panel, 'Nincs mentve – a Mentés után marad így.', '');
        }
    });

    // Escape: előbb a panel zárul, nem az egész ablak.
    document.addEventListener('keydown', function (esemeny) {
        if (esemeny.key !== 'Escape') {
            return;
        }

        var nyitott = document.querySelector('[data-sdh-ub-nyit][aria-expanded="true"]');

        if (nyitott) {
            esemeny.preventDefault();
            esemeny.stopPropagation();
            panelNyit(nyitott, false);
            nyitott.focus({ preventScroll: true });
        }
    }, true);

    // Betöltött űrlap: a rejtett lapfül helyett az első látható nyílik.
    document.addEventListener('sdh:urlap-betoltve', function (esemeny) {
        var torzs = esemeny.detail && esemeny.detail.torzs;

        if (torzs) {
            fulJavit(torzs);
        }
    });

    // Teljes oldalas űrlap (nem popup).
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            fulJavit(document);
        });
    } else {
        fulJavit(document);
    }

    window.SDH_UrlapBeallitas = { fulJavit: fulJavit, panelAlkalmaz: panelAlkalmaz };
})();
