/**
 * ügyféloldal (RMA), 0.27.
 *
 * Lapfülek (billentyűvel is), világos/sötét mód váltása (a böngésző
 * megjegyzi), az üzenetszál a legfrissebbnél nyílik, Ctrl+Enter küld,
 * a visszajelzés magától eltűnik. JavaScript nélkül minden panel látszik.
 */
(function () {
    'use strict';

    var html = document.documentElement;
    var test = document.querySelector('[data-rma-test]');
    var mobil = window.matchMedia ? window.matchMedia('(max-width: 760px)') : { matches: false };

    /* ---- Mód ---- */

    var temaGomb = document.querySelector('[data-rma-tema]');

    if (temaGomb) {
        temaGomb.addEventListener('click', function () {
            var sotet = html.getAttribute('data-theme') !== 'dark';

            html.setAttribute('data-theme', sotet ? 'dark' : 'light');

            try {
                localStorage.setItem('sdh-rma-tema', sotet ? 'sotet' : 'vilagos');
            } catch (e) {}
        });
    }

    /* ---- Lapfülek ---- */

    function fulek() {
        return test ? Array.prototype.slice.call(test.querySelectorAll('[data-rma-ful]')) : [];
    }

    function van(kulcs) {
        return !!(test && test.querySelector('[data-rma-ful="' + kulcs + '"]'));
    }

    // Az első tartalmi fül: javításnál az Eszköz, árukiadásnál / rendelésnél a Termékek.
    var elso = (test && test.getAttribute('data-rma-elso')) || 'eszkoz';

    function valt(kulcs, fokusz) {
        if (!test) {
            return;
        }

        if (!van(kulcs)) {
            kulcs = mobil.matches ? 'osszegzes' : elso;
        }

        // Asztali nézetben az összegzés az oldalsávban mindig látszik.
        if (kulcs === 'osszegzes' && !mobil.matches) {
            kulcs = elso;
        }

        test.setAttribute('data-rma-aktiv', kulcs);

        fulek().forEach(function (ful) {
            var aktiv = ful.getAttribute('data-rma-ful') === kulcs;

            ful.classList.toggle('is-aktiv', aktiv);
            ful.setAttribute('aria-selected', aktiv ? 'true' : 'false');
            ful.tabIndex = aktiv ? 0 : -1;

            if (aktiv && fokusz) {
                ful.focus();
            }

            if (aktiv && ful.scrollIntoView) {
                ful.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        });

        Array.prototype.forEach.call(test.querySelectorAll('.rma-panelek > [data-rma-panel]'), function (panel) {
            panel.classList.toggle('is-aktiv', panel.getAttribute('data-rma-panel') === kulcs);
        });

        if (kulcs === 'uzenetek') {
            var szal = test.querySelector('[data-rma-szal]');

            if (szal) {
                szal.scrollTop = szal.scrollHeight;
            }
        }

        try {
            sessionStorage.setItem('sdh-rma-ful', kulcs);
        } catch (e) {}

        if (window.history && history.replaceState) {
            history.replaceState(null, '', '#' + kulcs);
        }
    }

    if (test) {
        var kezdo = (location.hash || '').replace('#', '');

        if (!van(kezdo)) {
            kezdo = test.getAttribute('data-rma-alap') || '';
        }

        if (!van(kezdo)) {
            try {
                kezdo = sessionStorage.getItem('sdh-rma-ful') || '';
            } catch (e) {
                kezdo = '';
            }
        }

        valt(van(kezdo) ? kezdo : (mobil.matches ? 'osszegzes' : elso));

        test.addEventListener('click', function (esemeny) {
            var ful = esemeny.target.closest('[data-rma-ful]');

            if (ful) {
                valt(ful.getAttribute('data-rma-ful'));
            }
        });

        test.querySelector('.rma-fulek').addEventListener('keydown', function (esemeny) {
            if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].indexOf(esemeny.key) === -1) {
                return;
            }

            var lathato = fulek().filter(function (ful) { return ful.offsetParent !== null; });
            var most = lathato.indexOf(document.activeElement);
            var uj = esemeny.key === 'Home' ? 0
                : esemeny.key === 'End' ? lathato.length - 1
                : (most + (esemeny.key === 'ArrowRight' ? 1 : -1) + lathato.length) % lathato.length;

            esemeny.preventDefault();
            valt(lathato[uj].getAttribute('data-rma-ful'), true);
        });

        var meretValt = function () {
            valt(test.getAttribute('data-rma-aktiv') || elso);
        };

        if (mobil.addEventListener) {
            mobil.addEventListener('change', meretValt);
        } else if (mobil.addListener) {
            mobil.addListener(meretValt);
        }
    }

    /* ---- Üzenet ---- */

    var urlap = document.querySelector('[data-rma-uz-urlap]');

    if (urlap) {
        var mezo = urlap.querySelector('textarea');
        var gomb = urlap.querySelector('button[type="submit"]');

        mezo.addEventListener('input', function () {
            mezo.style.height = 'auto';
            mezo.style.height = Math.min(mezo.scrollHeight + 2, 140) + 'px';
        });

        mezo.addEventListener('keydown', function (esemeny) {
            // Enter küld, Shift+Enter új sor – érintőképernyőn (nincs Shift) az Enter sort tör, a gomb küld.
            var erinto = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

            if (esemeny.key === 'Enter' && !esemeny.isComposing && (esemeny.ctrlKey || esemeny.metaKey || (!esemeny.shiftKey && !erinto))) {
                esemeny.preventDefault();

                if (urlap.requestSubmit) {
                    urlap.requestSubmit();
                } else {
                    urlap.submit();
                }
            }
        });

        urlap.addEventListener('submit', function (esemeny) {
            if (!mezo.value.trim()) {
                esemeny.preventDefault();
                mezo.focus();

                return;
            }

            // Kétszeri beküldés ellen.
            setTimeout(function () { gomb.disabled = true; }, 0);
        });
    }

    /* ---- Visszajelzés ---- */

    Array.prototype.forEach.call(document.querySelectorAll('[data-rma-jelzes]'), function (jel) {
        setTimeout(function () {
            jel.classList.add('is-eltunik');
            setTimeout(function () { jel.remove(); }, 450);
        }, 7000);
    });

    // A ?h= / ?ok= ne maradjon a címben (újratöltéskor ne jöjjön újra).
    if (window.history && history.replaceState && /[?&](h|ok)=/.test(location.search)) {
        var cim = new URL(location.href);

        cim.searchParams.delete('h');
        cim.searchParams.delete('ok');
        history.replaceState(null, '', cim.pathname + (cim.search ? cim.search : '') + location.hash);
    }
}());

/* ------------------------------------------------------------------ */
/* Teljes képernyő (0.61.2)                                            */
/* ------------------------------------------------------------------ */
/*
 * Nagy monitoron az ügyféloldal ablaka kitöltheti a képernyőt. A böngésző
 * saját teljes képernyőjét használjuk (Fullscreen API); ha a böngésző nem
 * engedi, a gomb egyszerűen nem csinál semmit, és a felület marad.
 */
(function () {
    'use strict';

    var gomb = document.querySelector('[data-rma-teljes]');

    if (!gomb || !document.documentElement.requestFullscreen) {
        if (gomb) {
            gomb.hidden = true;
        }

        return;
    }

    function allapot() {
        document.documentElement.classList.toggle('is-teljes', !!document.fullscreenElement);
    }

    gomb.addEventListener('click', function () {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            document.documentElement.requestFullscreen().catch(function () { /* a böngésző nem engedte */ });
        }
    });

    document.addEventListener('fullscreenchange', allapot);
    allapot();
}());

/* Online időpontfoglalás (F6, 0.88.0): napválasztó – a kiválasztott nap időpontjai látszanak. */
(function () {
    'use strict';

    var urlap = document.querySelector('[data-rma-foglalas]');

    if (!urlap) {
        return;
    }

    var napok = urlap.querySelectorAll('[data-rma-fogl-nap]');
    var csoportok = urlap.querySelectorAll('[data-rma-fogl-idok]');

    function valaszt(nap) {
        napok.forEach(function (g) {
            var be = g.getAttribute('data-rma-fogl-nap') === nap;
            g.classList.toggle('is-aktiv', be);
            g.setAttribute('aria-pressed', be ? 'true' : 'false');
        });
        csoportok.forEach(function (c) {
            c.querySelectorAll('input[type="radio"]').forEach(function (r) {
                if (c.getAttribute('data-rma-fogl-idok') !== nap) {
                    r.checked = false;
                }
            });

            if (c.getAttribute('data-rma-fogl-idok') === nap) {
                c.removeAttribute('data-rejtve');
            } else {
                c.setAttribute('data-rejtve', '');
            }
        });
    }

    napok.forEach(function (g) {
        g.addEventListener('click', function () {
            valaszt(g.getAttribute('data-rma-fogl-nap'));
        });
    });
}());
