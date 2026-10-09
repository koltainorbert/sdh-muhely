/**
 * SDH Műhely – ügyféloldal (RMA), 0.27.
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

    function valt(kulcs, fokusz) {
        if (!test) {
            return;
        }

        if (!van(kulcs)) {
            kulcs = mobil.matches ? 'osszegzes' : 'eszkoz';
        }

        // Asztali nézetben az összegzés az oldalsávban mindig látszik.
        if (kulcs === 'osszegzes' && !mobil.matches) {
            kulcs = 'eszkoz';
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

        valt(van(kezdo) ? kezdo : (mobil.matches ? 'osszegzes' : 'eszkoz'));

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
            valt(test.getAttribute('data-rma-aktiv') || 'eszkoz');
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
            if (esemeny.key === 'Enter' && (esemeny.ctrlKey || esemeny.metaKey)) {
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
