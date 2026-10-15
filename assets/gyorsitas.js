/**
 * SDH Műhely – Hardveres gyorsítás (0.65.0).
 *
 * Egy weboldal nem tudja átállítani a böngésző beállítását (ez biztonsági korlát:
 * csak a felhasználó vagy a rendszergazda írhatja át). Amit a felület megtehet, és
 * meg is tesz:
 *   1. Észleli, hogy a böngésző a GPU-t használja-e (WebGL-próba: a „szoftveres"
 *      megjelenítőt – SwiftShader, llvmpipe, Microsoft Basic Render – felismeri).
 *   2. Ha nincs gyorsítás: egyszer figyelmezteti a felhasználót, és megmutatja az
 *      adott böngészőre a pontos lépéseket (Beállítások › Rendszer › Hardveres gyorsítás).
 *   3. Addig is „gyenge gép" módba kapcsol (html.sdh-gyenge-gep): kikapcsolja a
 *      homályítást és a nehéz árnyékokat, hogy a CPU-n is gyors maradjon.
 *   4. Kiírja az állapotot a Beállítások › Rendszer › Sebesség lapra.
 * Telepített gépeken a Chrome / Edge házirenddel kikényszeríthető: tools/hardveres-gyorsitas/.
 */
(function () {
    'use strict';

    if (window.SDH_GPU) {
        return;
    }

    var B = window.SDH_MUHELY || {};
    var G = B.gpu || {};
    var KULCS = 'sdh-gpu-elrejt';
    var SZOFTVERES = /swiftshader|llvmpipe|softpipe|software|basic render|microsoft basic|gdi generic|mesa offscreen/i;

    function bongeszo() {
        var u = navigator.userAgent || '';

        if (/Edg\//.test(u)) { return 'edge'; }
        if (/Firefox\//.test(u)) { return 'firefox'; }
        if (/OPR\/|Opera/.test(u)) { return 'opera'; }
        if (/Chrome\//.test(u)) { return 'chrome'; }
        if (/Safari\//.test(u)) { return 'safari'; }

        return 'egyeb';
    }

    function gl(szigoru) {
        var v = document.createElement('canvas');
        var opc = { failIfMajorPerformanceCaveat: !!szigoru, powerPreference: 'high-performance' };

        try {
            return v.getContext('webgl2', opc) || v.getContext('webgl', opc) || v.getContext('experimental-webgl', opc);
        } catch (x) {
            return null;
        }
    }

    function megjelenito(k) {
        try {
            var info = k.getExtension('WEBGL_debug_renderer_info');

            return String(info ? k.getParameter(info.UNMASKED_RENDERER_WEBGL) : k.getParameter(k.RENDERER) || '');
        } catch (x) {
            return '';
        }
    }

    /** 'gyors' | 'szoftveres' | 'ismeretlen' */
    function vizsgal() {
        var k = gl(true);
        var r = '';

        if (k) {
            r = megjelenito(k);

            return { allapot: SZOFTVERES.test(r) ? 'szoftveres' : 'gyors', megjelenito: r };
        }

        // Szigorú próba elbukott: vagy szoftveres a megjelenítés, vagy nincs WebGL egyáltalán.
        k = gl(false);

        if (k) {
            r = megjelenito(k);

            return { allapot: 'szoftveres', megjelenito: r };
        }

        return { allapot: 'ismeretlen', megjelenito: '' };
    }

    var LEPESEK = {
        chrome: 'Chrome: Beállítások › Rendszer › „Hardveres gyorsítás használata, ha rendelkezésre áll" – kapcsold be, majd „Újraindítás". (Gyors út: írd a címsorba: chrome://settings/system)',
        edge: 'Edge: Beállítások › Rendszer és teljesítmény › „Hardveres gyorsítás használata, ha rendelkezésre áll" – kapcsold be, majd „Újraindítás". (Gyors út: edge://settings/system)',
        firefox: 'Firefox: Beállítások › Általános › Teljesítmény – vedd ki a pipát az „Ajánlott teljesítménybeállítások használata" mellől, majd pipáld be a „Hardveres gyorsítás használata, ha rendelkezésre áll" sort, és indítsd újra.',
        opera: 'Opera: Beállítások › Haladó › Rendszer › „Hardveres gyorsítás használata, ha rendelkezésre áll" – kapcsold be, majd indítsd újra.',
        safari: 'Safari: macOS-en a hardveres gyorsítás mindig be van kapcsolva – ha mégis lassú, indítsd újra a gépet és frissítsd a rendszert.',
        egyeb: 'Keresd a böngésző Beállítások › Rendszer (Teljesítmény) lapján a „Hardveres gyorsítás" kapcsolót, kapcsold be, majd indítsd újra a böngészőt.'
    };

    var NEV = { chrome: 'Chrome', edge: 'Edge', firefox: 'Firefox', opera: 'Opera', safari: 'Safari', egyeb: 'a böngésző' };

    function tarol(k, v) {
        try { window.localStorage.setItem(k, v); } catch (x) { /* nincs tár */ }
    }

    function olvas(k) {
        try { return window.localStorage.getItem(k) || ''; } catch (x) { return ''; }
    }

    function felirat(a) {
        if (a.allapot === 'gyors') {
            return 'Hardveres gyorsítás: be (' + (a.megjelenito || 'GPU') + ')';
        }

        if (a.allapot === 'szoftveres') {
            return 'Hardveres gyorsítás: KI – a megjelenítést a processzor végzi' + (a.megjelenito ? ' (' + a.megjelenito + ')' : '');
        }

        return 'Hardveres gyorsítás: nem állapítható meg (a böngésző nem ad WebGL-t, ami gyakran ugyanezt jelenti)';
    }

    function allapotSor(a) {
        var sor = document.querySelectorAll('[data-sdh-gpu-allapot]');

        Array.prototype.forEach.call(sor, function (el) {
            el.textContent = felirat(a);
            el.setAttribute('data-allapot', a.allapot);
        });
    }

    function sav(a) {
        var b = bongeszo();
        var el = document.createElement('div');

        el.className = 'sdh-gpu-sav';
        el.setAttribute('role', 'status');
        el.innerHTML =
            '<strong class="sdh-gpu-sav__cim">Kapcsold be a hardveres gyorsítást – sokkal simább lesz a program</strong>' +
            '<p class="sdh-gpu-sav__szoveg"></p>' +
            '<div class="sdh-gpu-sav__gombok">' +
            '<button type="button" class="sdh-gomb sdh-gpu-ujra">Újraellenőrzés</button>' +
            '<button type="button" class="sdh-gomb sdh-gpu-elrejt">Ne mutasd 7 napig</button>' +
            '</div>';
        el.querySelector('.sdh-gpu-sav__szoveg').textContent =
            'Most ' + NEV[b] + ' a processzorral rajzol, ezért a mozgások és az árnyékok lassabbak. ' + LEPESEK[b];
        document.body.appendChild(el);

        el.querySelector('.sdh-gpu-elrejt').addEventListener('click', function () {
            tarol(KULCS, String(Date.now() + 7 * 864e5));
            el.remove();
        });

        el.querySelector('.sdh-gpu-ujra').addEventListener('click', function () {
            var u = vizsgal();

            alkalmaz(u);

            if (u.allapot === 'gyors') {
                el.remove();
            } else {
                el.querySelector('.sdh-gpu-sav__cim').textContent = 'Még mindig szoftveres a megjelenítés – indítsd újra a böngészőt a kapcsoló után.';
            }
        });
    }

    function alkalmaz(a) {
        var gyoker = document.documentElement;

        window.SDH_GPU = { allapot: a.allapot, megjelenito: a.megjelenito, bongeszo: bongeszo() };
        gyoker.setAttribute('data-sdh-gpu', a.allapot);
        // „Gyenge gép": szoftveres rajzolásnál nincs homályítás és nehéz árnyék (gyorsitas.css).
        gyoker.classList.toggle('sdh-gyenge-gep', a.allapot !== 'gyors' && G.gyenge !== false);
        allapotSor(a);
        document.dispatchEvent(new CustomEvent('sdh:gpu', { detail: window.SDH_GPU }));
    }

    function indit() {
        var a = vizsgal();

        alkalmaz(a);

        if (a.allapot === 'gyors' || G.figyelmeztet === false) {
            return;
        }

        if (parseInt(olvas(KULCS), 10) > Date.now()) {
            return;
        }

        sav(a);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', indit);
    } else {
        indit();
    }
}());
