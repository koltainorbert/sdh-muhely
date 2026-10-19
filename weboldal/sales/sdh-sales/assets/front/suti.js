/*
 * SDH Értékesítés – süti-hozzájárulás (banner, beállító ablak, Consent Mode v2 frissítés, blokkolt szkriptek élesítése).
 *
 * - A döntés az `sdh_suti` sütiben: „1|<verzió>|<s><m><p>|<unix idő>” (s = statisztika, m = marketing, p = preferencia).
 *   Ha a verzió eltér a beállítottól, vagy lejárt (`honap`), újra kérdezünk.
 * - A `<script type="text/plain" data-sdh-suti="<kategória>" [data-src]>` elemeket hozzájárulás után, dokumentum-sorrendben
 *   élesíti. Visszavont hozzájárulásnál törli a Google-sütiket és újratölti az oldalt (a futó szkriptet nem lehet kivenni).
 * - Global Privacy Control (`navigator.globalPrivacyControl`): a marketing alapból ki, az „Elfogadom” sem kapcsolja be.
 * - Nyilvános API: `window.SDH_SUTI.megnyit()`, `.dontes()`, `.engedett(kat)`, `.elfogad()`, `.elutasit()`;
 *   minden `.s-suti-megnyit` elem kattintásra megnyitja a beállító ablakot. Esemény: `document` → `sdh:suti` (detail = döntés).
 * - WP Consent API: `wp_set_consent()` (ha betöltődött), így a Site Kit és társai követik.
 * - Node alatt (`require('./suti.js')`) a tiszta függvényeket adja vissza (teszt).
 */
(function (gyar) {
    'use strict';

    var api = gyar();

    if (typeof module === 'object' && module && module.exports) {
        module.exports = api;
    }

    if (typeof window !== 'undefined' && typeof document !== 'undefined' && !window.SDH_SUTI_NINCS) {
        api.indit(window, document);
    }
}(function () {
    'use strict';

    var SUTI = 'sdh_suti';
    var SUTI_ID = 'sdh_suti_id';
    var KAT = ['statisztika', 'marketing', 'preferencia'];

    /* ================================================================
     * Tiszta függvények (Node-ban tesztelhetők)
     * ============================================================= */

    /** Egy süti értéke a `document.cookie` szövegből (vagy null). */
    function sutiErtek(cookie, nev) {
        var m = String(cookie || '').match(new RegExp('(?:^|;\\s*)' + nev + '=([^;]*)'));

        if (!m) {
            return null;
        }

        try {
            return decodeURIComponent(m[1]);
        } catch (e) {
            return null;
        }
    }

    /** „1|1.0|101|1760000000” → {verzio, kat:{statisztika,marketing,preferencia}, ido} vagy null. */
    function elemez(ertek) {
        if (typeof ertek !== 'string') {
            return null;
        }

        var p = ertek.split('|');

        if (p.length < 4 || p[0] !== '1' || !/^[01]{3}$/.test(p[2]) || !/^\d{9,11}$/.test(p[3])) {
            return null;
        }

        return {
            verzio: p[1],
            kat: { statisztika: p[2].charAt(0) === '1', marketing: p[2].charAt(1) === '1', preferencia: p[2].charAt(2) === '1' },
            ido: parseInt(p[3], 10)
        };
    }

    /** Döntés → süti-érték. */
    function kodol(kat, verzio, ido) {
        return '1|' + verzio + '|' + (kat.statisztika ? '1' : '0') + (kat.marketing ? '1' : '0') + (kat.preferencia ? '1' : '0') + '|' + Math.floor(ido);
    }

    /** A döntés lejárati ideje (ms): a döntés + `honap` naptári hónap. */
    function lejar(ido, honap) {
        var d = new Date(ido * 1000);

        d.setMonth(d.getMonth() + honap);

        return d.getTime();
    }

    /**
     * Érvényes-e még a döntés: ugyanaz a szabályzat-verzió, és nem járt le. Ha nem, újra kérdezünk.
     * @param {object|null} d   elemez() eredménye
     * @param {string} verzio   a beállított verzió
     * @param {number} honap    élettartam hónapban
     * @param {number} most     ms
     */
    function ervenyes(d, verzio, honap, most) {
        return !!d && d.verzio === String(verzio) && lejar(d.ido, honap) > most;
    }

    /** Kell-e a sávot mutatni. */
    function kerdezni(cookie, verzio, honap, most) {
        return !ervenyes(elemez(sutiErtek(cookie, SUTI)), verzio, honap, most);
    }

    /** Consent Mode v2 `update` objektum egy döntésből. */
    function consentAllapot(kat) {
        var g = function (b) { return b ? 'granted' : 'denied'; };

        return {
            analytics_storage: g(kat.statisztika),
            ad_storage: g(kat.marketing),
            ad_user_data: g(kat.marketing),
            ad_personalization: g(kat.marketing),
            functionality_storage: g(kat.preferencia),
            personalization_storage: g(kat.preferencia)
        };
    }

    /** WP Consent API kategóriák. */
    function wpConsent(kat) {
        var a = function (b) { return b ? 'allow' : 'deny'; };

        return {
            functional: 'allow',
            preferences: a(kat.preferencia),
            statistics: a(kat.statisztika),
            'statistics-anonymous': a(kat.statisztika),
            marketing: a(kat.marketing)
        };
    }

    /** Gyorsgombok: „Elfogadom” (GPC mellett a marketing nélkül), „Elutasítom”. */
    function mindet(elfogad, gpc) {
        return { statisztika: !!elfogad, marketing: !!elfogad && !gpc, preferencia: !!elfogad };
    }

    /** Engedett-e a kategória. A `szukseges` mindig. */
    function engedett(kat, nev) {
        if (nev === 'szukseges') {
            return true;
        }

        return !!(kat && kat[nev]);
    }

    /** Visszavont-e valamit az új döntés a régihez képest (újratöltés kell). */
    function visszavont(regi, uj) {
        if (!regi) {
            return false;
        }

        for (var i = 0; i < KAT.length; i++) {
            if (regi[KAT[i]] && !uj[KAT[i]]) {
                return true;
            }
        }

        return false;
    }

    /** Véletlen anonim azonosító (base64url, 22 karakter). */
    function ujAzon(veletlen) {
        var b = veletlen(22);
        var abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        var s = '';

        for (var i = 0; i < 22; i++) {
            s += abc.charAt(b[i] & 63);
        }

        return s;
    }

    /* ================================================================
     * Böngésző
     * ============================================================= */

    function indit(w, d) {
        // A Front osztály a szerveradatot `window.SDH_SUTI = {…}` alá teszi; utána a nevet az API veszi át.
        var adat = w.SDH_SUTI && !w.SDH_SUTI.megnyit ? w.SDH_SUTI : {};
        var cfg = {
            verzio: String(adat.verzio || '1.0'),
            honap: parseInt(adat.honap || 6, 10),
            rest: String(adat.rest || ''),
            naplo: adat.naplo !== false
        };

        var gpc = !!(w.navigator && w.navigator.globalPrivacyControl === true);
        var aktualis = null;
        var elozoFokusz = null;

        function gtag() {
            w.dataLayer = w.dataLayer || [];
            w.dataLayer.push(arguments);
        }

        function sutiIr(nev, ertek, honap) {
            var biztos = w.location && w.location.protocol === 'https:' ? '; Secure' : '';
            d.cookie = nev + '=' + encodeURIComponent(ertek) + '; Max-Age=' + Math.round(honap * 30.4375 * 86400) + '; Path=/; SameSite=Lax' + biztos;
        }

        function veletlen(n) {
            var a = new Uint8Array(n);

            if (w.crypto && w.crypto.getRandomValues) {
                w.crypto.getRandomValues(a);
            } else {
                for (var i = 0; i < n; i++) {
                    a[i] = Math.floor(Math.random() * 256);
                }
            }

            return a;
        }

        function azon() {
            var a = sutiErtek(d.cookie, SUTI_ID);

            if (!a || !/^[A-Za-z0-9_-]{16,40}$/.test(a)) {
                a = ujAzon(veletlen);
            }

            sutiIr(SUTI_ID, a, cfg.honap);

            return a;
        }

        function googleSutiTorol() {
            var host = w.location.hostname;
            var dom = ['', host, '.' + host];
            var resz = host.split('.');

            if (resz.length > 2) {
                dom.push('.' + resz.slice(-2).join('.'));
            }

            String(d.cookie).split(';').forEach(function (c) {
                var n = c.split('=')[0].trim();

                if (/^(_ga|_ga_.+|_gid|_gat.*|_gcl_.+|_fbp|_fbc)$/.test(n)) {
                    dom.forEach(function (x) {
                        d.cookie = n + '=; Max-Age=0; Path=/' + (x ? '; Domain=' + x : '');
                    });
                }
            });
        }

        /** A blokkolt szkriptek élesítése (dokumentum-sorrendben, egyszer). */
        function elesit(kat) {
            var lista = d.querySelectorAll('script[type="text/plain"][data-sdh-suti]');

            Array.prototype.forEach.call(lista, function (regi) {
                var k = regi.getAttribute('data-sdh-suti');

                if (!engedett(kat, k) || regi.hasAttribute('data-sdh-suti-kesz')) {
                    return;
                }

                regi.setAttribute('data-sdh-suti-kesz', '1');

                var uj = d.createElement('script');

                Array.prototype.forEach.call(regi.attributes, function (a) {
                    if (['type', 'data-sdh-suti', 'data-sdh-suti-kesz', 'data-src'].indexOf(a.name) === -1) {
                        uj.setAttribute(a.name, a.value);
                    }
                });

                if (regi.getAttribute('data-src')) {
                    uj.src = regi.getAttribute('data-src');
                    uj.async = false;
                } else {
                    uj.text = regi.text;
                }

                regi.parentNode.insertBefore(uj, regi.nextSibling);
            });
        }

        function consentApi(kat) {
            var p = wpConsent(kat);

            if (typeof w.wp_set_consent === 'function') {
                Object.keys(p).forEach(function (k) {
                    w.wp_set_consent(k, p[k]);
                });

                return true;
            }

            return false;
        }

        function alkalmaz(kat) {
            gtag('consent', 'update', consentAllapot(kat));

            if (!consentApi(kat)) {
                w.addEventListener('load', function () { consentApi(kat); }, { once: true });
            }

            elesit(kat);

            try {
                d.dispatchEvent(new w.CustomEvent('sdh:suti', { detail: { kategoriak: kat, verzio: cfg.verzio } }));
            } catch (e) { /* régi böngésző */ }
        }

        function naplo(kat) {
            if (!cfg.naplo || !cfg.rest || typeof w.fetch !== 'function') {
                return;
            }

            try {
                w.fetch(cfg.rest.replace(/\/$/, '') + '/suti', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'omit',
                    keepalive: true,
                    body: JSON.stringify({ azon: azon(), verzio: cfg.verzio, kategoriak: kat, gpc: gpc })
                })['catch'](function () {});
            } catch (e) { /* a napló nem akadályozhatja a döntést */ }
        }

        function dont(kat) {
            var regi = aktualis;

            aktualis = { statisztika: !!kat.statisztika, marketing: !!kat.marketing, preferencia: !!kat.preferencia };
            sutiIr(SUTI, kodol(aktualis, cfg.verzio, Date.now() / 1000), cfg.honap);
            naplo(aktualis);
            sav(false);
            bezar();

            if (visszavont(regi, aktualis)) {
                gtag('consent', 'update', consentAllapot(aktualis));
                consentApi(aktualis);
                googleSutiTorol();
                w.location.reload();

                return;
            }

            alkalmaz(aktualis);
        }

        /* ---------------- Felület ---------------- */

        var gyoker = d.getElementById('s-suti');
        var modal = d.getElementById('s-suti-modal');
        var ablak = modal ? modal.querySelector('.s-suti-ablak') : null;

        function sav(lathato) {
            if (gyoker) {
                gyoker.hidden = !lathato;
            }
        }

        function fokuszalhatok() {
            if (!ablak) {
                return [];
            }

            return Array.prototype.filter.call(
                ablak.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])'),
                function (e) { return e.offsetParent !== null || e === d.activeElement; }
            );
        }

        function valasztokBeallit() {
            if (!modal) {
                return;
            }

            var k = aktualis || mindet(false, gpc);

            Array.prototype.forEach.call(modal.querySelectorAll('.s-suti-valaszto'), function (i) {
                var n = i.getAttribute('data-suti-kat');

                if (n !== 'szukseges') {
                    i.checked = !!k[n];
                }
            });

            var g = modal.querySelector('.s-suti-gpc');

            if (g) {
                g.hidden = !gpc;
            }
        }

        function megnyit() {
            if (!modal) {
                return;
            }

            elozoFokusz = d.activeElement;
            valasztokBeallit();
            modal.hidden = false;
            d.documentElement.classList.add('s-suti-nyitva');

            var f = fokuszalhatok();

            (f.length ? f[0] : ablak).focus();
        }

        function bezar() {
            if (!modal || modal.hidden) {
                return;
            }

            modal.hidden = true;
            d.documentElement.classList.remove('s-suti-nyitva');

            if (elozoFokusz && typeof elozoFokusz.focus === 'function' && d.contains(elozoFokusz)) {
                elozoFokusz.focus();
            } else if (gyoker && !gyoker.hidden) {
                var b = gyoker.querySelector('button');

                if (b) {
                    b.focus();
                }
            }
        }

        function kivalasztott() {
            var k = { statisztika: false, marketing: false, preferencia: false };

            if (modal) {
                Array.prototype.forEach.call(modal.querySelectorAll('.s-suti-valaszto'), function (i) {
                    var n = i.getAttribute('data-suti-kat');

                    if (n !== 'szukseges') {
                        k[n] = !!i.checked;
                    }
                });
            }

            return k;
        }

        d.addEventListener('click', function (e) {
            var t = e.target && e.target.closest ? e.target.closest('[data-suti-muvelet], .s-suti-megnyit') : null;

            if (!t) {
                return;
            }

            if (t.classList.contains('s-suti-megnyit')) {
                e.preventDefault();
                megnyit();

                return;
            }

            var m = t.getAttribute('data-suti-muvelet');

            if (m === 'elfogad') {
                dont(mindet(true, gpc));
            } else if (m === 'elutasit') {
                dont(mindet(false, gpc));
            } else if (m === 'ment') {
                dont(kivalasztott());
            } else if (m === 'beallit') {
                megnyit();
            } else if (m === 'bezar') {
                bezar();
            }
        });

        d.addEventListener('keydown', function (e) {
            if (!modal || modal.hidden) {
                return;
            }

            if (e.key === 'Escape' || e.key === 'Esc') {
                e.preventDefault();
                bezar();

                return;
            }

            if (e.key === 'Tab') {
                var f = fokuszalhatok();

                if (!f.length) {
                    e.preventDefault();

                    return;
                }

                var elso = f[0];
                var utolso = f[f.length - 1];

                if (e.shiftKey && (d.activeElement === elso || !ablak.contains(d.activeElement))) {
                    e.preventDefault();
                    utolso.focus();
                } else if (!e.shiftKey && (d.activeElement === utolso || !ablak.contains(d.activeElement))) {
                    e.preventDefault();
                    elso.focus();
                }
            }
        });

        /* ---------------- Indulás ---------------- */

        var d0 = elemez(sutiErtek(d.cookie, SUTI));

        if (ervenyes(d0, cfg.verzio, cfg.honap, Date.now())) {
            aktualis = d0.kat;
            alkalmaz(aktualis);
        } else {
            sav(true);
        }

        w.SDH_SUTI = {
            megnyit: megnyit,
            bezar: bezar,
            elfogad: function () { dont(mindet(true, gpc)); },
            elutasit: function () { dont(mindet(false, gpc)); },
            dontes: function () { return aktualis ? { statisztika: aktualis.statisztika, marketing: aktualis.marketing, preferencia: aktualis.preferencia } : null; },
            engedett: function (k) { return engedett(aktualis, k); },
            verzio: cfg.verzio
        };
    }

    return {
        SUTI: SUTI,
        sutiErtek: sutiErtek,
        elemez: elemez,
        kodol: kodol,
        lejar: lejar,
        ervenyes: ervenyes,
        kerdezni: kerdezni,
        consentAllapot: consentAllapot,
        wpConsent: wpConsent,
        mindet: mindet,
        engedett: engedett,
        visszavont: visszavont,
        ujAzon: ujAzon,
        indit: indit
    };
}));
