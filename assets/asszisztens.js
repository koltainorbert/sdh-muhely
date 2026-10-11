/**
 * SDH Műhely – AI-asszisztens: az „élő" figura és a beszélgetés (0.38).
 *
 * Minden CRM-oldal jobb alsó sarkában él egy kis neon lény: lélegzik,
 * pislog, a szemével követi az egeret, elalszik, ha sokáig nincs mozgás,
 * és buborékban szól, ha valami sürgős (a pulzusból – csapat.js), ha új
 * verzió jött, vagy ha egy űrlap hibát dob. Rákattintva nyílik a
 * beszélgetés (F1 is nyitja).
 *
 * A válaszban jöhet:
 *  - szöveg (biztonságos, egyszerű Markdown),
 *  - javaslat-kártya: módosítás, amit itt kell engedélyezni / elutasítani
 *    (átírásnál és törlésnél a megerősítő kód beírásával), utána visszaállítható,
 *  - felület-utasítás: „mutat" – kiemeli az elemet a képernyőn és a lény
 *    odarepül egy képregénybuborékkal; „megnyit" – gomb egy oldalra / rekordra.
 *
 * A popup (dialog) nyitva tartása alatt a lény beköltözik a popupba, hogy
 * ott is kezelhető legyen (a modális ablak a lapot különben inertté teszi).
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};
    var A = B.ai || {};

    if (!B.ajax || window.SDH_AI) {
        return;
    }

    window.SDH_AI = true;

    function app() {
        return window.SDH_MUHELY_APP || {};
    }

    function e(szoveg) {
        return String(szoveg === null || szoveg === undefined ? '' : szoveg)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function tarOlvas(k, alap) {
        try {
            var v = window.localStorage.getItem(k);

            return v === null ? alap : JSON.parse(v);
        } catch (x) {
            return alap;
        }
    }

    function tarIr(k, v) {
        try {
            window.localStorage.setItem(k, JSON.stringify(v));
        } catch (x) { /* privát mód */ }
    }

    function ma() {
        var d = new Date();

        return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
    }

    function kerdes(akcio, adat, post) {
        adat = adat || {};

        var cim = new URL(B.ajax, window.location.origin);
        var opciok = { credentials: 'same-origin' };

        if (post) {
            var fd = new FormData();

            fd.set('action', 'sdh_muhely_' + akcio);
            fd.set('_wpnonce', B.nonce || '');
            Object.keys(adat).forEach(function (k) {
                if (Array.isArray(adat[k])) {
                    adat[k].forEach(function (v) { fd.append(k + '[]', v); });
                } else {
                    fd.set(k, adat[k]);
                }
            });
            opciok.method = 'POST';
            opciok.body = fd;
        } else {
            cim.searchParams.set('action', 'sdh_muhely_' + akcio);
            cim.searchParams.set('_wpnonce', B.nonce || '');
            Object.keys(adat).forEach(function (k) { cim.searchParams.set(k, adat[k]); });
        }

        return fetch(cim.toString(), opciok).then(function (v) {
            return v.text().then(function (t) {
                var j;

                try {
                    j = JSON.parse(t);
                } catch (x) {
                    throw new Error(v.status === 403 || t === '-1' ? 'Lejárt a munkamenet – frissítsd az oldalt.' : 'A szerver válasza nem értelmezhető.');
                }

                if (!j || !j.success) {
                    var h = new Error((j && j.data && j.data.uzenet) || 'A művelet nem sikerült.');

                    h.adat = j && j.data;
                    throw h;
                }

                return j.data;
            });
        });
    }

    /* ---------------------------------------------------------------- */
    /* Biztonságos, egyszerű Markdown                                    */
    /* ---------------------------------------------------------------- */

    function link(szoveg, url) {
        var u = String(url).trim();

        try {
            var x = new URL(u, window.location.origin);

            if (!/^https?:$/.test(x.protocol)) {
                return szoveg;
            }

            var sajat = x.host === window.location.host;

            return '<a href="' + e(x.toString()) + '"' + (sajat ? '' : ' target="_blank" rel="noopener noreferrer"') + '>' + szoveg + '</a>';
        } catch (h) {
            return szoveg;
        }
    }

    function sorKozi(s) {
        var kodok = [];

        s = e(s).replace(/`([^`\n]+)`/g, function (m, k) {
            kodok.push('<code>' + k + '</code>');

            return '\u0000' + (kodok.length - 1) + '\u0000';
        });
        s = s.replace(/\[([^\]\n]{1,160})\]\(([^)\s]{1,400})\)/g, function (m, t, u) { return link(t, u.replace(/&amp;/g, '&')); });
        s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/(^|[\s(])_([^_\n]+)_(?=[\s).,!?]|$)/g, '$1<em>$2</em>');

        return s.replace(/\u0000(\d+)\u0000/g, function (m, i) { return kodok[+i]; });
    }

    function md(t) {
        var ki = [];
        var reszek = String(t || '').split(/```[a-zA-Z0-9_+-]*\n?/);

        reszek.forEach(function (r, i) {
            if (i % 2) {
                ki.push('<pre><code>' + e(r.replace(/\n$/, '')) + '</code></pre>');

                return;
            }

            r.split(/\n{2,}/).forEach(function (bek) {
                bek = bek.replace(/^\n+|\n+$/g, '');

                if (!bek) {
                    return;
                }

                var sorok = bek.split('\n');

                if (sorok.length === 1 && /^#{1,4}\s+/.test(sorok[0])) {
                    ki.push('<p class="sdh-ai-md-cim">' + sorKozi(sorok[0].replace(/^#{1,4}\s+/, '')) + '</p>');

                    return;
                }

                if (sorok.every(function (s) { return /^\s*([-*•]|\d+[.)])\s+/.test(s); })) {
                    var szamos = /^\s*\d/.test(sorok[0]);

                    ki.push((szamos ? '<ol>' : '<ul>') + sorok.map(function (s) {
                        return '<li>' + sorKozi(s.replace(/^\s*([-*•]|\d+[.)])\s+/, '')) + '</li>';
                    }).join('') + (szamos ? '</ol>' : '</ul>'));
                } else {
                    ki.push('<p>' + sorok.map(sorKozi).join('<br>') + '</p>');
                }
            });
        });

        return ki.join('');
    }

    /* ---------------------------------------------------------------- */
    /* Ikonok                                                            */
    /* ---------------------------------------------------------------- */

    var IKON = {
        kuld: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3.5 10h12M11 5l5 5-5 5"/></svg>',
        uj: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3.5 10a6.5 6.5 0 1 0 2-4.7"/><path d="M3.5 3.5v4h4"/></svg>',
        x: '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M3.6 3.6l4.8 4.8M8.4 3.6l-4.8 4.8"/></svg>',
        teljesBe: '<svg class="sdh-modal__teljes-be" viewBox="0 0 12 12" aria-hidden="true"><path d="M3.2 6.6V3.2h3.4zM8.8 5.4v3.4H5.4z"/></svg>',
        teljesKi: '<svg class="sdh-modal__teljes-ki" viewBox="0 0 12 12" aria-hidden="true"><path d="M5.6 2.4v3.2H2.4zM6.4 9.6V6.4h3.2z"/></svg>',
        fel: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6.5 9v7.5h-3V9zM6.5 9.5l3-6c1.4 0 2.2 1 2 2.3L11 8.5h4.3c1 0 1.7.9 1.5 1.9l-1.1 5c-.2.7-.8 1.1-1.5 1.1H6.5"/></svg>',
        le: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6.5 11V3.5h-3V11zM6.5 10.5l3 6c1.4 0 2.2-1 2-2.3L11 11.5h4.3c1 0 1.7-.9 1.5-1.9l-1.1-5c-.2-.7-.8-1.1-1.5-1.1H6.5"/></svg>',
        tanul: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M2.5 7.5 10 4l7.5 3.5L10 11z"/><path d="M5.5 9v4c1.2 1.3 2.7 2 4.5 2s3.3-.7 4.5-2V9"/></svg>',
        nyil: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h11M11 6l4 4-4 4"/></svg>',
        pajzs: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2.8 4.2 5v4.6c0 3.7 2.5 6.3 5.8 7.6 3.3-1.3 5.8-3.9 5.8-7.6V5z"/><path d="m7.4 10 1.9 1.9 3.5-3.6"/></svg>'
    };

    /* ================================================================ */
    /* Javaslat-kártyák (a panelen és az Asszisztens oldalon is)         */
    /* ================================================================ */

    var SZINT = { hozzaad: 'Hozzáadás', atir: 'Átírás', torol: 'Törlés', olvas: 'Adatlekérés' };
    var ALLAPOT = { javaslat: 'Jóváhagyásra vár', vegrehajtva: 'Végrehajtva', elutasitva: 'Elutasítva', visszavonva: 'Visszaállítva', hiba: 'Nem sikerült', lejart: 'Lejárt', fut: 'Folyamatban' };

    function kartyaHtml(k) {
        if (!k || !k.id) {
            return '';
        }

        var valt = (k.valtozasok || []).map(function (v) {
            return '<li><span class="sdh-ai-kartya__mezo">' + e(v.mezo) + '</span>' +
                (v.regi !== '' ? '<del>' + e(v.regi) + '</del><span class="sdh-ai-kartya__nyil">' + IKON.nyil + '</span>' : '') +
                '<ins>' + e(v.uj) + '</ins></li>';
        }).join('');

        var dontes = '';

        if (k.allapot === 'javaslat') {
            dontes = (k.kod ? '<label class="sdh-ai-kartya__kod">Biztonsági megerősítés – írd be a kódot: <b>' + e(k.kod) + '</b>' +
                '<input type="text" inputmode="numeric" maxlength="6" autocomplete="off" data-ai-kod aria-label="Megerősítő kód"></label>' : '') +
                '<div class="sdh-ai-kartya__gombok">' +
                '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-ai-dont="igen"' + (k.kod ? ' disabled' : '') + '>Engedélyezem</button>' +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-ai-dont="nem">Elutasítom</button></div>';
        } else if (k.allapot === 'vegrehajtva' || k.allapot === 'visszavonva' || k.allapot === 'hiba') {
            dontes = (k.eredmeny ? '<p class="sdh-ai-kartya__eredmeny">' + e(k.eredmeny) + '</p>' : '') +
                (k.visszaallithato ? '<details class="sdh-ai-kartya__vissza"><summary>Visszaállítás…</summary>' +
                    '<label>A visszaállításhoz írd be a művelet számát: <b>' + k.id + '</b><input type="text" inputmode="numeric" maxlength="8" data-ai-vkod autocomplete="off" aria-label="A művelet száma"></label>' +
                    '<button type="button" class="sdh-gomb" data-ai-vissza disabled>Visszaállítom</button></details>' : '');
        }

        return '<div class="sdh-ai-kartya sdh-ai-kartya--' + e(k.szint) + ' is-' + e(k.allapot) + '" data-ai-kartya="' + k.id + '"' + (k.kod ? ' data-kod="' + e(k.kod) + '"' : '') + '>' +
            '<div class="sdh-ai-kartya__fej"><span class="sdh-ai-kartya__szint">' + e(SZINT[k.szint] || k.szint) + '</span>' +
            '<strong>' + e(k.cim) + '</strong><span class="sdh-ai-kartya__allapot">' + e(ALLAPOT[k.allapot] || k.allapot) + '</span></div>' +
            '<p class="sdh-ai-kartya__leiras">' + e(k.leiras) + '</p>' +
            (valt ? '<ul class="sdh-ai-kartya__valt">' + valt + '</ul>' : '') +
            dontes +
            '<p class="sdh-ai-kartya__lab">' + IKON.pajzs + '#' + k.id + ' · ' + e(k.ido) + (k.ki && !k.sajat ? ' · ' + e(k.ki) : '') +
            (k.allapot === 'javaslat' && k.szint !== 'olvas' ? ' · végrehajtás előtt mentés készül' : '') + '</p>' +
            '</div>';
    }

    function kartyaCsere(regi, k) {
        var tmp = document.createElement('div');

        tmp.innerHTML = kartyaHtml(k);

        if (tmp.firstChild) {
            regi.replaceWith(tmp.firstChild);
        }
    }

    document.addEventListener('input', function (ev) {
        var kod = ev.target.closest && ev.target.closest('[data-ai-kod]');

        if (kod) {
            var k = kod.closest('[data-ai-kartya]');
            var gomb = k.querySelector('[data-ai-dont="igen"]');

            gomb.disabled = kod.value.trim() !== k.getAttribute('data-kod');

            return;
        }

        var vkod = ev.target.closest && ev.target.closest('[data-ai-vkod]');

        if (vkod) {
            var kv = vkod.closest('[data-ai-kartya]');

            kv.querySelector('[data-ai-vissza]').disabled = vkod.value.trim() !== kv.getAttribute('data-ai-kartya');
        }
    });

    document.addEventListener('click', function (ev) {
        var d = ev.target.closest && ev.target.closest('[data-ai-dont]');

        if (d) {
            var k = d.closest('[data-ai-kartya]');
            var kod = k.querySelector('[data-ai-kod]');

            Array.prototype.forEach.call(k.querySelectorAll('button'), function (g) { g.disabled = true; });
            d.textContent = d.getAttribute('data-ai-dont') === 'igen' ? 'Végrehajtom…' : 'Elutasítom…';
            allapot('gondolkodik');

            kerdes('ai_dont', { id: k.getAttribute('data-ai-kartya'), dontes: d.getAttribute('data-ai-dont'), kod: kod ? kod.value.trim() : '' }, true)
                .then(function (v) {
                    kartyaCsere(k, v.kartya);
                    allapot(v.kartya.allapot === 'vegrehajtva' ? 'orul' : 'nyugi', 2200);

                    if (v.folytat && lenyBe) {
                        kuld('', true);
                    }

                    pulzusKer();
                })
                .catch(function (h) {
                    if (h.adat && h.adat.kartya) {
                        kartyaCsere(k, h.adat.kartya);
                    } else {
                        Array.prototype.forEach.call(k.querySelectorAll('button'), function (g) { g.disabled = false; });
                        d.textContent = d.getAttribute('data-ai-dont') === 'igen' ? 'Engedélyezem' : 'Elutasítom';
                    }

                    allapot('jelez', 2500);
                    kartyaHiba(k, h.message);
                });

            return;
        }

        var v = ev.target.closest && ev.target.closest('[data-ai-vissza]');

        if (v) {
            var kv = v.closest('[data-ai-kartya]');
            var vkod = kv.querySelector('[data-ai-vkod]');
            var eroltet = v.getAttribute('data-eroltet') === '1';

            v.disabled = true;
            kerdes('ai_visszaallit', { id: kv.getAttribute('data-ai-kartya'), kod: vkod ? vkod.value.trim() : '', eroltet: eroltet ? '1' : '0' }, true)
                .then(function (r) {
                    kartyaCsere(kv, r.kartya);
                })
                .catch(function (h) {
                    v.disabled = false;

                    if (h.adat && h.adat.eroltetheto) {
                        v.setAttribute('data-eroltet', '1');
                        v.textContent = 'Mégis visszaállítom';
                    }

                    kartyaHiba(kv, h.message);
                });
        }
    });

    function kartyaHiba(k, szoveg) {
        var h = k.querySelector('.sdh-ai-kartya__hiba');

        if (!h) {
            h = document.createElement('p');
            h.className = 'sdh-ai-kartya__hiba';
            k.appendChild(h);
        }

        h.textContent = szoveg;
    }

    /* Az Asszisztens oldal kártyái, mentés, beállítás. */
    Array.prototype.forEach.call(document.querySelectorAll('[data-kartya]'), function (hely) {
        try {
            hely.innerHTML = kartyaHtml(JSON.parse(hely.getAttribute('data-kartya')));
        } catch (x) { /* hibás adat */ }
    });

    /* Személyiség-csúszkák (humor, élénkség): a kijelzés húzás közben frissül. */
    document.addEventListener('input', function (ev) {
        var doboz = ev.target.closest && ev.target.closest('[data-sdh-ai-csuszka]');

        if (doboz && ev.target.type === 'range') {
            doboz.style.setProperty('--p', ev.target.value + '%');
            doboz.querySelector('output').textContent = ev.target.value + '%';
        }
    });

    var beallUrlap = document.querySelector('[data-sdh-ai-beallitas]');

    if (beallUrlap) {
        beallUrlap.addEventListener('submit', function (ev) {
            ev.preventDefault();

            var fd = new FormData(beallUrlap);
            var adat = {};

            fd.forEach(function (v, k) {
                if (k.slice(-2) === '[]') {
                    (adat[k.slice(0, -2)] = adat[k.slice(0, -2)] || []).push(v);
                } else {
                    adat[k] = v;
                }
            });
            kerdes('ai_beallit', adat, true)
                .then(function (d) { window.location.href = d.vissza; })
                .catch(function (h) { window.alert(h.message); });
        });

        var tesztGomb = beallUrlap.querySelector('[data-sdh-ai-teszt]');

        tesztGomb.addEventListener('click', function () {
            var hely = beallUrlap.querySelector('[data-sdh-ai-teszt-uzenet]');

            hely.textContent = 'Próbálom…';
            kerdes('ai_teszt', {}, true)
                .then(function (d) { hely.textContent = d.uzenet; })
                .catch(function (h) { hely.textContent = h.message; });
        });
    }

    var mentesGomb = document.querySelector('[data-sdh-ai-mentes]');

    if (mentesGomb) {
        mentesGomb.addEventListener('click', function () {
            var hely = document.querySelector('[data-sdh-ai-mentes-uzenet]');

            mentesGomb.disabled = true;
            hely.textContent = 'Mentés folyamatban…';
            kerdes('ai_mentes', {}, true)
                .then(function (d) {
                    hely.textContent = d.uzenet;
                    window.setTimeout(function () { window.location.reload(); }, 1200);
                })
                .catch(function (h) {
                    hely.textContent = h.message;
                    mentesGomb.disabled = false;
                });
        });
    }

    /* ================================================================ */
    /* Az élő figura                                                      */
    /* ================================================================ */

    var lenyBe = !!A.be;
    var NEV = A.nev || 'Szikra';
    var gyoker = null;
    var leny = null;
    var buborek = null;
    var panel = null;
    var lista = null;
    var mezo = null;
    var javaslatDoboz = null;
    var betoltve = false;
    var fut = false;
    var allapotIdo = 0;
    var utolsoMozgas = Date.now();
    var utolsoTipp = 0;

    if (!lenyBe) {
        return;
    }

    function epit() {
        gyoker = document.createElement('div');
        gyoker.className = 'sdh-ai';
        gyoker.setAttribute('data-allapot', 'nyugi');
        gyoker.innerHTML =
            '<div class="sdh-ai__buborek" role="status" hidden><button type="button" class="sdh-ai__buborek-x" aria-label="Bezárás">' + IKON.x + '</button>' +
            '<div class="sdh-ai__buborek-szoveg"></div><div class="sdh-ai__buborek-gombok"></div></div>' +
            '<button type="button" class="sdh-ai__leny" aria-label="' + e(NEV) + ' – AI-asszisztens (F1)" title="' + e(NEV) + ' – kérdezz bátran! (F1)">' +
            '<span class="sdh-ai__aura" aria-hidden="true"></span>' +
            // Kezek, lábak, antenna: ettől tud integetni, sétálni, táncolni.
            '<span class="sdh-ai__lab sdh-ai__lab--b" aria-hidden="true"></span><span class="sdh-ai__lab sdh-ai__lab--j" aria-hidden="true"></span>' +
            '<span class="sdh-ai__kar sdh-ai__kar--b" aria-hidden="true"></span><span class="sdh-ai__kar sdh-ai__kar--j" aria-hidden="true"></span>' +
            '<span class="sdh-ai__csap" aria-hidden="true"><i></i></span>' +
            '<span class="sdh-ai__test" aria-hidden="true"><span class="sdh-ai__mag"></span><span class="sdh-ai__fenyfolt"></span>' +
            '<span class="sdh-ai__arc">' +
            '<span class="sdh-ai__szemoldok sdh-ai__szemoldok--b"></span><span class="sdh-ai__szemoldok sdh-ai__szemoldok--j"></span>' +
            '<span class="sdh-ai__szem sdh-ai__szem--b"><span class="sdh-ai__pupilla"></span></span>' +
            '<span class="sdh-ai__szem sdh-ai__szem--j"><span class="sdh-ai__pupilla"></span></span>' +
            '<span class="sdh-ai__pir sdh-ai__pir--b"></span><span class="sdh-ai__pir sdh-ai__pir--j"></span>' +
            '<span class="sdh-ai__szaj"></span><span class="sdh-ai__csepp"></span></span></span>' +
            // Kellék (kávé, könyv, napszemüveg…) és a szikrák / konfetti helye.
            '<span class="sdh-ai__kellek" aria-hidden="true"></span>' +
            '<span class="sdh-ai__szikrak" aria-hidden="true"></span>' +
            '<span class="sdh-ai__zzz" aria-hidden="true">z<span>z</span><span>z</span></span>' +
            '<span class="sdh-ai__jelveny" hidden></span></button>' +
            '<section class="sdh-ai-panel" role="dialog" aria-label="' + e(NEV) + ' – AI-asszisztens" hidden>' +
            '<header class="sdh-ai-panel__fej">' +
            '<button type="button" class="sdh-modal__bezar" aria-label="Bezárás" title="Bezárás" data-ai-zar>' + IKON.x + '</button>' +
            '<button type="button" class="sdh-modal__teljes" aria-label="Teljes képernyő" title="Teljes képernyő" data-ai-teljes>' + IKON.teljesBe + IKON.teljesKi + '</button>' +
            '<span class="sdh-ai-mini" aria-hidden="true"><span></span><span></span></span>' +
            '<div class="sdh-ai-panel__cim"><strong>' + e(NEV) + '</strong><span data-ai-statusz>Itt vagyok, kérdezz bátran</span></div>' +
            '<button type="button" class="sdh-ai-panel__ikon" data-ai-uj title="Új beszélgetés" aria-label="Új beszélgetés">' + IKON.uj + '</button>' +
            '</header>' +
            '<div class="sdh-ai-panel__uzenetek" role="log" aria-live="polite"></div>' +
            '<div class="sdh-ai-panel__javaslatok"></div>' +
            '<form class="sdh-ai-panel__iro"><textarea rows="1" maxlength="3000" placeholder="Kérdezz bármit, vagy kérd a segítségem…" aria-label="Kérdés"></textarea>' +
            '<button type="submit" class="sdh-cs-kuldgomb sdh-cs-kuldgomb--nagy" aria-label="Küldés">' + IKON.kuld + '</button></form>' +
            '<p class="sdh-ai-panel__lab">' + IKON.pajzs + 'Adatot csak a jóváhagyásoddal módosítok, előtte mentek · F1</p>' +
            '</section>';

        leny = gyoker.querySelector('.sdh-ai__leny');
        buborek = gyoker.querySelector('.sdh-ai__buborek');
        panel = gyoker.querySelector('.sdh-ai-panel');
        lista = gyoker.querySelector('.sdh-ai-panel__uzenetek');
        mezo = gyoker.querySelector('.sdh-ai-panel__iro textarea');
        javaslatDoboz = gyoker.querySelector('.sdh-ai-panel__javaslatok');

        document.body.appendChild(gyoker);

        leny.addEventListener('click', function () {
            // Túra közben a figura a buborék mellett áll: rákattintva nem történik semmi (a gombok vezetnek).
            if (tura) {
                gesztus('bolint', 500);

                return;
            }

            if (gyoker.classList.contains('is-repul')) {
                visszaRepul();

                return;
            }

            setalVege();

            // Négy gyors kattintás: elszédül (és inkább nem nyit-zár tovább).
            var most = Date.now();

            kattintasok = kattintasok.filter(function (t) { return most - t < 1600; });
            kattintasok.push(most);

            if (kattintasok.length >= 4) {
                kattintasok = [];
                zar();
                allapot('szedul', 2800);
                gesztus('porog', 900);
                beszol('csiklandoz', true);

                return;
            }

            if (panel.hidden) {
                nyit();
            } else {
                zar();
            }
        });

        // Ha sokáig rajta tartod az egeret: elpirul, szívecskék.
        var simiIdo = 0;

        leny.addEventListener('mouseenter', function () {
            window.clearTimeout(simiIdo);
            simiIdo = window.setTimeout(function () {
                if (szabad()) {
                    allapot('orul', 1600);
                    szikrak(5, 'sziv');
                }
            }, 1300);
        });
        leny.addEventListener('mouseleave', function () { window.clearTimeout(simiIdo); });

        gyoker.querySelector('[data-ai-zar]').addEventListener('click', zar);
        gyoker.querySelector('[data-ai-teljes]').addEventListener('click', function () {
            panel.classList.toggle('is-teljes');
            tarIr('sdh-ai-teljes', panel.classList.contains('is-teljes'));
        });
        gyoker.querySelector('[data-ai-uj]').addEventListener('click', function () {
            kerdes('ai_uj', {}, true).catch(function () {});
            lista.innerHTML = '';
            udvozol();
            javaslatok();
            mezo.focus();
        });
        buborek.querySelector('.sdh-ai__buborek-x').addEventListener('click', buborekZar);
        buborek.addEventListener('mouseenter', function () { window.clearTimeout(buborekIdo); });

        var urlap = gyoker.querySelector('.sdh-ai-panel__iro');

        urlap.addEventListener('submit', function (ev) {
            ev.preventDefault();
            kuld(mezo.value);
        });
        mezo.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
                ev.preventDefault();
                kuld(mezo.value);
            }

            if (ev.key === 'Escape') {
                zar();
            }
        });
        mezo.addEventListener('input', function () {
            mezo.style.height = 'auto';
            mezo.style.height = Math.min(mezo.scrollHeight, 140) + 'px';
        });
        mezo.addEventListener('focus', function () { allapot('figyel'); });
        mezo.addEventListener('blur', function () { if (!fut) { allapot('nyugi'); } });

        lista.addEventListener('click', listaKatt);

        if (tarOlvas('sdh-ai-teljes', false)) {
            panel.classList.add('is-teljes');
        }

        elet();
        helyez();
    }

    /* ---- Hangulatok ---- */

    /*
     * Hangulatok (data-allapot, a rajz az asszisztens.css-ben): nyugi, figyel,
     * gondolkodik, beszel, orul, nevet, szomoru, jelez, mutat, alszik, kacsint,
     * meglepett, ijedt, szedul, asit, buszke, kivancsi, kave, olvas.
     */
    function allapot(nev, visszaMs) {
        if (!gyoker) {
            return;
        }

        window.clearTimeout(allapotIdo);
        gyoker.setAttribute('data-allapot', nev);

        if (visszaMs) {
            allapotIdo = window.setTimeout(function () { gyoker.setAttribute('data-allapot', fut ? 'gondolkodik' : (tura ? 'mutat' : 'nyugi')); }, visszaMs);
        }
    }

    function hangulat() {
        return gyoker ? gyoker.getAttribute('data-allapot') : '';
    }

    /* ---- Gesztusok, kellékek, szikrák ---- */

    var gesztusIdo = 0;
    var kellekIdo = 0;
    var kattintasok = [];

    // A kellékek rajza (vonalas SVG, a figura elé kerül).
    var KELLEK = {
        napszemuveg: '<svg viewBox="0 0 64 64"><path d="M9 25h46" stroke="#0b1020" stroke-width="3" stroke-linecap="round" fill="none"/><path d="M12 25h16v6a8 8 0 0 1-16 0zM36 25h16v6a8 8 0 0 1-16 0z" fill="#0b1020"/><path d="M15 28l5-2M39 28l5-2" stroke="#fff" stroke-width="2" stroke-linecap="round" opacity=".7"/></svg>',
        kave: '<svg viewBox="0 0 64 64"><path d="M38 38h16v9a8 8 0 0 1-16 0z" fill="#fff" stroke="#0b1020" stroke-width="2.5" stroke-linejoin="round"/><path d="M54 40h3a4 4 0 0 1 0 8h-3" fill="none" stroke="#0b1020" stroke-width="2.5"/><path class="sdh-ai__goz" d="M43 33c-2-3 2-4 0-7M49 33c-2-3 2-4 0-7" fill="none" stroke="#0b1020" stroke-width="2" stroke-linecap="round" opacity=".55"/></svg>',
        konyv: '<svg viewBox="0 0 64 64"><path d="M32 40c-5-4-12-5-19-4v16c7-1 14 0 19 4 5-4 12-5 19-4V36c-7-1-14 0-19 4z" fill="#fff" stroke="#0b1020" stroke-width="2.5" stroke-linejoin="round"/><path d="M32 40v16M18 42c4-.4 7 0 10 1.5M18 47c4-.4 7 0 10 1.5M46 42c-4-.4-7 0-10 1.5" fill="none" stroke="#0b1020" stroke-width="1.6" stroke-linecap="round" opacity=".6"/></svg>',
        parti: '<svg viewBox="0 0 64 64"><path d="M32 -6 44 14H20z" fill="#ff5fa2" stroke="#0b1020" stroke-width="2.5" stroke-linejoin="round"/><path d="M26 6l12 4M23 11l18 2" stroke="#fff" stroke-width="2" stroke-linecap="round"/><circle cx="32" cy="-7" r="4" fill="#ffd23f" stroke="#0b1020" stroke-width="2"/></svg>',
        halo: '<svg viewBox="0 0 64 64"><path d="M16 14C22 0 44 0 50 12l8 12-6 1-4-7c-10-3-22-3-32-4z" fill="#6c8cff" stroke="#0b1020" stroke-width="2.5" stroke-linejoin="round"/><circle cx="57" cy="26" r="4.5" fill="#fff" stroke="#0b1020" stroke-width="2"/></svg>',
        nagyito: '<svg viewBox="0 0 64 64"><circle cx="46" cy="40" r="9" fill="rgba(255,255,255,.55)" stroke="#0b1020" stroke-width="3"/><path d="m53 47 7 7" stroke="#0b1020" stroke-width="4" stroke-linecap="round"/></svg>'
    };

    /** Rövid testmozdulat (ugrás, pörgés, integetés, tánc…): is-g-<név> osztály a megadott ideig. */
    function gesztus(nev, ms) {
        if (!gyoker || nyugodtMozgas) {
            return;
        }

        window.clearTimeout(gesztusIdo);
        Array.prototype.slice.call(gyoker.classList).forEach(function (o) {
            if (o.indexOf('is-g-') === 0) {
                gyoker.classList.remove(o);
            }
        });
        void gyoker.offsetWidth;
        gyoker.classList.add('is-g-' + nev);
        gesztusIdo = window.setTimeout(function () { gyoker.classList.remove('is-g-' + nev); }, ms || 900);
    }

    /** Kellék a figurára (üres név: leveszi). */
    function kellek(nev, ms) {
        var hely = gyoker.querySelector('.sdh-ai__kellek');

        window.clearTimeout(kellekIdo);
        hely.innerHTML = nev && KELLEK[nev] ? KELLEK[nev] : '';
        hely.setAttribute('data-kellek', nev || '');

        if (nev && ms) {
            kellekIdo = window.setTimeout(function () { kellek(''); }, ms);
        }
    }

    /** Szikrák, szívecskék vagy konfetti a figura körül. */
    function szikrak(db, fajta) {
        if (nyugodtMozgas) {
            return;
        }

        var hely = gyoker.querySelector('.sdh-ai__szikrak');
        var i;

        for (i = 0; i < db; i++) {
            (function (n) {
                var sz = document.createElement('i');
                var szog = (-160 + Math.random() * 140) * Math.PI / 180;
                var tav = 34 + Math.random() * 46;

                sz.className = 'sdh-ai__szikra sdh-ai__szikra--' + (fajta || 'csillag');
                sz.style.setProperty('--x', (Math.cos(szog) * tav).toFixed(0) + 'px');
                sz.style.setProperty('--y', (Math.sin(szog) * tav).toFixed(0) + 'px');
                sz.style.setProperty('--f', (Math.random() * 360).toFixed(0) + 'deg');
                sz.style.setProperty('--s', String(n % 5));
                sz.style.animationDelay = (n * 45) + 'ms';
                hely.appendChild(sz);
                window.setTimeout(function () { sz.remove(); }, 1300 + n * 45);
            }(i));
        }
    }

    /* ---- Beszólások (helyben, API nélkül) ---- */

    var HUMOR = isNaN(parseInt(A.humor, 10)) ? 90 : Math.max(0, Math.min(100, parseInt(A.humor, 10)));
    var ELENK = isNaN(parseInt(A.elenkseg, 10)) ? 80 : Math.max(0, Math.min(100, parseInt(A.elenkseg, 10)));
    var nyugodtMozgas = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var utolsoBeszolas = Date.now() - 5 * 60000 + 90000; // az első legkorábban másfél perc múlva
    var mondott = {};

    var SZOVEG = {
        ures: [
            'Én itt lent ülök, és figyelem a pixeleket. Mind megvan.',
            'Megszámoltam a munkalapokat. Aztán még egyszer. Unatkozó gömb vagyok.',
            'Ha valamit nem találsz, szólj – én ezen a képernyőn lakom, ismerem a környéket.',
            'Tudtad? A **/** billentyűvel bárhonnan a keresőbe ugrasz. Én ugrani csak így tudok.',
            'Csavarhúzóm nincs, de kereséshez, számoláshoz, magyarázáshoz elég jó vagyok.',
            'Nem iszom kávét. A te kávédra viszont nagyon vigyázok – ne a billentyűzet fölött!',
            'Ha elakadtál, nyomd meg az **F1**-et. Az a csengőm.',
            'Azt álmodtam, hogy minden munkalap határidőre elkészült. Aztán felébredtem… de dolgozunk rajta!',
            'Kerek vagyok, mint egy jó zárás. Erre büszke is vagyok.',
            'Egy jó szerviz titka: türelem, forrasztópáka, és valaki, aki megjegyzi a határidőket. Az utóbbi vagyok én.',
            'Ha gyorsan négyszer rám kattintasz, elszédülök. Ezt nem javaslatnak szántam.',
            'A legjobb hibaüzenet az, amelyik meg sem jelenik. A második legjobbat elmagyarázom.',
            'Kérdezz bátran! A legrosszabb, ami történhet, hogy elgondolkodom.',
            'Súgó, túra, kérdés–válasz: mindhárom én vagyok, csak más kalapban.',
            'Csendben vagyok, mert dolgozol. Látod, milyen jól nevelt gömb vagyok?',
            'Ha a „Vezess végig ezen az oldalon” gombra nyomsz, idegenvezető leszek. Zászlóm sajnos nincs.'
        ],
        reggel: [
            'Jó reggelt! Én már bemelegítettem: háromszor körbepörögtem.',
            'Reggel van, a kassza friss, a nap hosszú. Kezdhetjük!',
            'Jó reggelt! Megnézzük, mi vár ma? Kérdezd meg: „Mi a mai helyzet?”'
        ],
        del: [
            'Ebédidő! Én fényt eszem, de neked valami rendeset ajánlok.',
            'Dél van. A munkalapok megvárnak, az ebéd kihűl.'
        ],
        zaras: [
            'Mindjárt zárunk! A kasszát ne felejtsd – én nem tudom megszámolni, nincsenek ujjaim.',
            'Közeleg a zárás. Aki ma elkészült, annak szólt már valaki?',
            'Zárás előtt: kassza, nyitott lapok, lámpa. Engem nem kell lekapcsolni, alszom magamtól.'
        ],
        pentek: [
            'Péntek van! A munkalapok is érzik.',
            'Péntek délután: a hét utolsó határidői most a legbátrabbak.'
        ],
        hetfo: [
            'Hétfő. Semmi baj, együtt csináljuk végig.',
            'Új hét, tiszta lap. Illetve munkalap.'
        ],
        mentes: [
            'Mentve! Én is megjegyeztem – jó, én mindent megjegyzek.',
            'Elmentve. Szépen dolgozol!',
            'Megvan, biztonságban van. Pacsi! (Képzeletben, a kezem rövid.)',
            'Mentve. Egy gonddal kevesebb.'
        ],
        csiklandoz: [
            'Hé, ez csikis! Forog velem a képernyő…',
            'Juj, elszédültem! Most két egeret látok.',
            'Jó, jó, megadom magam! Miben segíthetek?'
        ],
        ebredes: [
            'Ásít… Nem aludtam, csak pihentettem a pupilláimat.',
            'Itt vagyok! Miről maradtam le?',
            'Hú, de jót szundítottam. Mehet a munka!'
        ],
        turaVege: [
            'Ennyi volt a körút! Borravaló helyett elfogadok egy kérdést.',
            'Végigjártuk! Ha valami kimaradt, kérdezz – vagy nézd meg a leírást.',
            'Kész a túra! Te figyeltél, én mutogattam. Jó csapat vagyunk.'
        ],
        turaKezdes: [
            'Idegenvezető üzemmód bekapcsolva. Kérem, kövessenek!',
            'Indulunk! Tartsd a kezed-lábad a képernyőn belül.',
            'Na, megmutatom, mi hol lakik ezen az oldalon.'
        ],
        hiba: [
            'Ajjaj, ezt nem szeretem látni. De megoldjuk!',
            'Hoppá. Semmi pánik, nézzük meg együtt.'
        ]
    };

    /** Egy mondat a megadott témából (ami ebben a munkamenetben még nem hangzott el). */
    function mondat(tema) {
        var lista = SZOVEG[tema] || [];
        var uj = lista.filter(function (m) { return !mondott[m]; });

        if (!uj.length) {
            lista.forEach(function (m) { delete mondott[m]; });
            uj = lista;
        }

        var m = uj[Math.floor(Math.random() * uj.length)] || '';

        mondott[m] = true;

        return m;
    }

    /**
     * Beszólás buborékban. Kötelező (rögtön jön) vagy alkalmi: az utóbbi csak
     * ha a humor engedi, eltelt elég idő, és épp nem zavar (nincs nyitva panel,
     * buborék, túra; a felhasználó nem gépel).
     */
    function beszol(tema, kotelezo) {
        if (HUMOR < 15 || !gyoker) {
            return false;
        }

        var koz = (4 + (100 - HUMOR) / 8) * 60000;

        if (!kotelezo && (!A.proaktiv || Date.now() - utolsoBeszolas < koz || !szabad() || Date.now() - utolsoBillentyu < 8000)) {
            return false;
        }

        if (kotelezo && (!panel.hidden || tura)) {
            return false;
        }

        var m = mondat(tema);

        if (!m) {
            return false;
        }

        utolsoBeszolas = Date.now();
        buborekMutat(m, [], { ms: 7000, allapot: tema === 'hiba' ? 'ijedt' : (tema === 'csiklandoz' ? 'szedul' : 'nevet') });

        return true;
    }

    /** A napszakhoz, naphoz illő téma (vagy az általános). */
    function napszakTema() {
        var d = new Date();
        var ora = d.getHours() + d.getMinutes() / 60;
        var oraDoboz = document.querySelector('[data-sdh-ora]');

        if (oraDoboz && oraDoboz.getAttribute('data-allapot') === 'hamar') {
            return 'zaras';
        }

        if (ora < 10 && Math.random() < .5) {
            return d.getDay() === 1 && Math.random() < .5 ? 'hetfo' : 'reggel';
        }

        if (ora >= 11.5 && ora < 13.5 && Math.random() < .35) {
            return 'del';
        }

        if (d.getDay() === 5 && ora >= 13 && Math.random() < .4) {
            return 'pentek';
        }

        return 'ures';
    }

    /* ---- Élet: pislogás, szemmozgás, alvás, önálló mozgás ---- */

    var utolsoBillentyu = 0;
    var szemZar = 0;
    var setalIdo = 0;

    /** Szabad-e magától mozognia: nincs nyitva semmi, nem vezet, nem alszik, látszik az oldal. */
    function szabad() {
        return !!gyoker && panel.hidden && buborek.hidden && !tura && !fut &&
            !gyoker.classList.contains('is-repul') && !gyoker.classList.contains('is-setal') &&
            hangulat() === 'nyugi' && document.visibilityState !== 'hidden';
    }

    /** Séta a képernyő alján: elballag balra, körülnéz, visszajön. */
    function setal() {
        var tav = Math.round(Math.min(320, window.innerWidth * .28) * (.45 + Math.random() * .55));
        var ido = Math.round(tav * 16);

        gyoker.classList.add('is-setal');
        leny.style.transition = 'transform ' + ido + 'ms linear';
        leny.style.transform = 'translateX(' + (-tav) + 'px)';

        setalIdo = window.setTimeout(function () {
            gyoker.classList.add('is-all');
            allapot('kivancsi', 1400);

            setalIdo = window.setTimeout(function () {
                gyoker.classList.remove('is-all');
                gyoker.classList.add('is-vissza');
                leny.style.transform = 'translateX(0)';
                setalIdo = window.setTimeout(setalVege, ido + 80);
            }, 1500);
        }, ido + 60);
    }

    function setalVege() {
        if (!gyoker || !gyoker.classList.contains('is-setal')) {
            return;
        }

        window.clearTimeout(setalIdo);
        gyoker.classList.remove('is-setal', 'is-all', 'is-vissza');
        leny.style.transition = '';
        leny.style.transform = '';
    }

    function elet() {
        var szemek = gyoker.querySelectorAll('.sdh-ai__szem');
        var pupillak = gyoker.querySelectorAll('.sdh-ai__pupilla');
        var kep = 0;
        var cel = { x: 0, y: 0 };

        function pupilla(x, y) {
            Array.prototype.forEach.call(pupillak, function (p) {
                p.style.transform = 'translate(' + x.toFixed(2) + 'px,' + y.toFixed(2) + 'px)';
            });
        }

        function pislog() {
            if (hangulat() !== 'alszik') {
                Array.prototype.forEach.call(szemek, function (s) {
                    s.classList.remove('is-pislog');
                    void s.offsetWidth;
                    s.classList.add('is-pislog');
                });
            }

            window.setTimeout(pislog, 2600 + Math.random() * 4200);
        }

        window.setTimeout(pislog, 1800);

        document.addEventListener('mousemove', function (ev) {
            utolsoMozgas = Date.now();

            if (hangulat() === 'alszik') {
                ebred();
            }

            cel.x = ev.clientX;
            cel.y = ev.clientY;

            if (kep || Date.now() < szemZar) {
                return;
            }

            kep = window.requestAnimationFrame(function () {
                kep = 0;

                var r = leny.getBoundingClientRect();
                var dx = cel.x - (r.left + r.width / 2);
                var dy = cel.y - (r.top + r.height / 2);
                var tav = Math.sqrt(dx * dx + dy * dy) || 1;
                var m = Math.min(1, tav / 260) * 3.2;

                pupilla(dx / tav * m, dy / tav * m);
            });
        }, { passive: true });

        ['keydown', 'click', 'scroll'].forEach(function (es) {
            document.addEventListener(es, function (ev) {
                utolsoMozgas = Date.now();

                if (es === 'keydown') {
                    utolsoBillentyu = Date.now();
                }

                if (hangulat() === 'alszik') {
                    ebred();
                }

                // Ha a felhasználó dolgozni kezd, a séta félbemarad: nem lábatlankodik.
                if (es !== 'scroll' && !(ev.target.closest && ev.target.closest('.sdh-ai'))) {
                    setalVege();
                }
            }, { passive: true, capture: true });
        });

        function ebred() {
            kellek('');
            allapot('asit', 1500);
            gesztus('nyujt', 1300);

            if (Math.random() * 100 < HUMOR * .5) {
                window.setTimeout(function () { beszol('ebredes', true); }, 1400);
            }
        }

        window.setInterval(function () {
            if (Date.now() - utolsoMozgas > 4 * 60000 && hangulat() === 'nyugi' && panel.hidden && !tura) {
                setalVege();
                allapot('alszik');
                kellek('halo');
            }
        }, 15000);

        /* Önálló élet: időnként magától csinál valamit. Az élénkség adja a ritmust. */
        var CSELEKVES = [
            [4, function () { // körülnéz
                szemZar = Date.now() + 1900;
                pupilla(-3, 0);
                window.setTimeout(function () { pupilla(3, -1); }, 600);
                window.setTimeout(function () { pupilla(0, -3); }, 1200);
                window.setTimeout(function () { pupilla(0, 0); }, 1800);
            }],
            [3, function () { allapot('kacsint', 800); }],
            [2, function () { allapot('asit', 1900); gesztus('nyujt', 1700); }],
            [3, function () { gesztus('ugral', 950); allapot('orul', 950); }],
            [2, function () { gesztus('porog', 900); allapot('meglepett', 900); }],
            [3, function () { gesztus('integet', 1700); allapot('orul', 1700); }],
            [2, function () { gesztus('tancol', 2600); allapot('nevet', 2600); }],
            [2, function () { kellek('kave', 3600); allapot('kave', 3600); }],
            [2, function () { kellek('konyv', 4200); allapot('olvas', 4200); }],
            [1, function () { kellek('napszemuveg', 3400); allapot('buszke', 3400); }],
            [1, function () { kellek('nagyito', 3000); allapot('kivancsi', 3000); gesztus('bolint', 700); }],
            [2, function () { gesztus('kukucs', 2800); }],
            [2, function () { szikrak(6, 'csillag'); allapot('meglepett', 1000); }],
            [3, setal],
            [3, function () { beszol(napszakTema(), false); }]
        ];
        var osszSuly = CSELEKVES.reduce(function (o, c) { return o + c[0]; }, 0);

        function eletLep() {
            if (ELENK > 0 && !nyugodtMozgas && szabad()) {
                var r = Math.random() * osszSuly;
                var i;

                for (i = 0; i < CSELEKVES.length; i++) {
                    r -= CSELEKVES[i][0];

                    if (r <= 0) {
                        CSELEKVES[i][1]();
                        break;
                    }
                }
            } else if (nyugodtMozgas && szabad()) {
                // Kevesebb mozgásnál sem néma: a beszólás megmarad.
                beszol(napszakTema(), false);
            }

            // 100% élénkségnél 7–14 mp, 50%-nál 20–40 mp, 10%-nál kb. 35–70 mp között jön a következő.
            var alap = 7000 + (100 - ELENK) * 300;

            window.setTimeout(eletLep, alap * (1 + Math.random()));
        }

        window.setTimeout(eletLep, 9000);

        // Sikeres mentés bárhol a rendszerben: örül, konfetti, néha egy jó szó.
        document.addEventListener('sdh:mentve', function () {
            if (tura || !panel.hidden) {
                return;
            }

            setalVege();
            allapot('orul', 1500);
            gesztus('ugral', 900);
            szikrak(9, 'konfetti');

            if (Math.random() * 100 < HUMOR * .3 && buborek.hidden) {
                window.setTimeout(function () { beszol('mentes', true); }, 500);
            }
        });
    }

    /* ---- Költözés a nyitott popupba (a modális ablak mögött inert lenne) ---- */

    function legfelsoModal() {
        var nyitott = [];

        try {
            nyitott = Array.prototype.slice.call(document.querySelectorAll('dialog:modal'));
        } catch (x) {
            nyitott = Array.prototype.slice.call(document.querySelectorAll('dialog[open]'));
        }

        return nyitott.length ? nyitott[nyitott.length - 1] : null;
    }

    function helyez() {
        var cel = legfelsoModal() || document.body;

        if (gyoker.parentNode !== cel) {
            var fokusz = gyoker.contains(document.activeElement);

            cel.appendChild(gyoker);

            if (fokusz && !panel.hidden) {
                mezo.focus();
            }
        }

        // A popup „zoom"-ja (app.js illesztés) ne nagyítsa / kicsinyítse a figurát.
        var z = cel !== document.body ? parseFloat(cel.style.zoom || '1') || 1 : 1;

        gyoker.style.zoom = z !== 1 ? String(1 / z) : '';
    }

    new MutationObserver(function () {
        if (gyoker) {
            window.requestAnimationFrame(helyez);
        }
    }).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['open'] });

    window.addEventListener('resize', function () {
        if (gyoker) {
            helyez();
        }
    });

    /* ---- Buborék (képregény) ---- */

    var buborekIdo = 0;

    function buborekMutat(szoveg, gombok, opciok) {
        opciok = opciok || {};

        // Végigvezetés közben a figura a túra buborékjánál áll: a sarokban nem szólal meg
        // (kivéve, amikor a túra egy felugró ablak miatt várakozik).
        if (tura && !tura.szunet) {
            return;
        }

        var sz = buborek.querySelector('.sdh-ai__buborek-szoveg');
        var g = buborek.querySelector('.sdh-ai__buborek-gombok');

        sz.innerHTML = opciok.html ? szoveg : md(szoveg);
        g.innerHTML = '';
        (gombok || []).forEach(function (gomb) {
            var b = document.createElement('button');

            b.type = 'button';
            b.className = 'sdh-ai__buborek-gomb' + (gomb.fo ? ' is-fo' : '');
            b.textContent = gomb.felirat;
            b.addEventListener('click', function () {
                buborekZar();
                gomb.tesz();
            });
            g.appendChild(b);
        });

        buborek.hidden = false;
        buborek.classList.remove('is-latszik');
        void buborek.offsetWidth;
        buborek.classList.add('is-latszik');
        allapot(opciok.allapot || 'beszel', 2600);

        window.clearTimeout(buborekIdo);

        if (opciok.ms !== 0) {
            buborekIdo = window.setTimeout(buborekZar, opciok.ms || 16000);
        }
    }

    function buborekZar() {
        window.clearTimeout(buborekIdo);
        buborek.classList.remove('is-latszik');
        window.setTimeout(function () { buborek.hidden = true; }, 220);
    }

    /* ================================================================ */
    /* A beszélgetés                                                      */
    /* ================================================================ */

    function nyit(kezdoKerdes) {
        buborekZar();
        panel.hidden = false;
        gyoker.classList.add('is-nyitva');
        requestAnimationFrame(function () { panel.classList.add('is-latszik'); });
        allapot('orul', 1200);

        var kesz = betoltve ? Promise.resolve() : elozmenyBetolt();

        kesz.then(function () {
            if (kezdoKerdes) {
                kuld(kezdoKerdes);
            } else {
                window.setTimeout(function () { mezo.focus(); }, 60);
            }
        });
    }

    function zar() {
        panel.classList.remove('is-latszik');
        panel.hidden = true;
        gyoker.classList.remove('is-nyitva');
        allapot('nyugi');
        leny.focus();
    }

    function udvozol() {
        var nev = String(A.en || '').split(' ').pop() || '';

        uzenetRajzol({
            szerep: 'assistant',
            szoveg: 'Szia' + (nev ? ' ' + nev : '') + '! **' + NEV + '** vagyok, a CRM asszisztense. Kérdezz bármit – elmondom, hogyan működik, megmutatom a képernyőn, utánanézek egy munkalapnak vagy ügyfélnek, és ha kéred, elő is készítem a módosítást. **Adatot csak a te jóváhagyásoddal változtatok**, és előtte mindig mentést készítek.',
            nincsMuvelet: true
        });
    }

    function elozmenyBetolt() {
        betoltve = true;
        lista.innerHTML = '<div class="sdh-ai-gepel"><span></span><span></span><span></span></div>';

        return kerdes('ai_elozmeny').then(function (d) {
            lista.innerHTML = '';
            udvozol();

            if (!d.mukodik) {
                uzenetRajzol({ szerep: 'hiba', szoveg: 'Még nincs bekapcsolva az AI (hiányzik a Claude API-kulcs). Az adminisztrátor az **Asszisztens › Beállítások** lapon állíthatja be.' });
            }

            (d.uzenetek || []).forEach(uzenetRajzol);
            (d.kartyak || []).forEach(function (k) { kartyaRajzol(k); });
            javaslatok();
            gorgetLe();
        }).catch(function (h) {
            lista.innerHTML = '';
            udvozol();
            uzenetRajzol({ szerep: 'hiba', szoveg: h.message });
        });
    }

    function gorgetLe() {
        lista.scrollTop = lista.scrollHeight;
    }

    function uzenetRajzol(u) {
        var d = document.createElement('div');

        if (u.szerep === 'esemeny') {
            d.className = 'sdh-ai-esemeny';
            d.textContent = String(u.szoveg).replace(/^#\d+\s*/, '').slice(0, 220);
            lista.appendChild(d);

            return d;
        }

        var ai = u.szerep === 'assistant' || u.szerep === 'hiba';

        d.className = 'sdh-ai-uz ' + (ai ? 'sdh-ai-uz--ai' : 'sdh-ai-uz--en') + (u.szerep === 'hiba' ? ' sdh-ai-uz--hiba' : '');
        d.innerHTML = ai ? md(u.szoveg) : '<p>' + e(u.szoveg).replace(/\n/g, '<br>') + '</p>';

        if (u.szerep === 'assistant' && u.id && !u.nincsMuvelet) {
            d.setAttribute('data-ai-uz', u.id);
            d.insertAdjacentHTML('beforeend',
                '<div class="sdh-ai-uz__muveletek">' +
                '<button type="button" data-ai-ertek="1" class="' + (u.ertekeles === 1 ? 'is-aktiv' : '') + '" title="Jó válasz" aria-label="Jó válasz">' + IKON.fel + '</button>' +
                '<button type="button" data-ai-ertek="-1" class="' + (u.ertekeles === -1 ? 'is-aktiv' : '') + '" title="Rossz válasz" aria-label="Rossz válasz">' + IKON.le + '</button>' +
                '<button type="button" data-ai-tanit title="Jegyezd meg ezt a választ (jóváhagyással a tudástárba kerül)">' + IKON.tanul + '<span>Jegyezd meg</span></button>' +
                '</div>');
        }

        lista.appendChild(d);

        return d;
    }

    function kartyaRajzol(k) {
        var tmp = document.createElement('div');

        tmp.innerHTML = kartyaHtml(k);

        var regi = lista.querySelector('[data-ai-kartya="' + k.id + '"]');

        if (regi) {
            regi.replaceWith(tmp.firstChild);
        } else if (tmp.firstChild) {
            lista.appendChild(tmp.firstChild);
        }
    }

    function listaKatt(ev) {
        var ert = ev.target.closest('[data-ai-ertek]');

        if (ert) {
            var uz = ert.closest('[data-ai-uz]');
            var ertek = ert.classList.contains('is-aktiv') ? 0 : parseInt(ert.getAttribute('data-ai-ertek'), 10);

            Array.prototype.forEach.call(uz.querySelectorAll('[data-ai-ertek]'), function (b) { b.classList.remove('is-aktiv'); });

            if (ertek) {
                ert.classList.add('is-aktiv');
                allapot(ertek > 0 ? 'orul' : 'szomoru', 1800);
            }

            kerdes('ai_ertekel', { id: uz.getAttribute('data-ai-uz'), ertek: ertek }, true).catch(function () {});

            return;
        }

        var tanit = ev.target.closest('[data-ai-tanit]');

        if (tanit) {
            tanit.disabled = true;
            kerdes('ai_tanit', { id: tanit.closest('[data-ai-uz]').getAttribute('data-ai-uz') }, true)
                .then(function (d) {
                    kartyaRajzol(d.kartya);
                    gorgetLe();
                })
                .catch(function (h) {
                    tanit.disabled = false;
                    uzenetRajzol({ szerep: 'hiba', szoveg: h.message });
                });

            return;
        }

        var ugras = ev.target.closest('[data-ai-ugras]');

        if (ugras) {
            var popup = ugras.getAttribute('data-popup');

            if (popup && app().nyit) {
                app().nyit(popup, ugras.getAttribute('data-id') || '0');
            } else if (ugras.getAttribute('data-url')) {
                window.location.href = ugras.getAttribute('data-url');
            }

            return;
        }

    }

    /* ---- Javasolt kérdések az oldalhoz ---- */

    function modulKulcs() {
        return document.body.getAttribute('data-sdh-modul') || (new URLSearchParams(window.location.search).get('page') || '').replace(/^sdh-muhely-?/, '') || 'attekintes';
    }

    function javaslatok() {
        var m = modulKulcs();
        var t = {
            attekintes: ['Mi a mai helyzet?', 'Melyik munkalapok késnek?', 'Hogyan veszek fel új munkalapot?'],
            munkalapok: ['Hogyan veszek fel új munkalapot?', 'Mit jelentenek az állapotok?', 'Hogyan működik a bevizsgálási díj?'],
            ugyfelek: ['Hogyan veszek fel új ügyfelet?', 'Hogyan kérek céges számlát egy ügyfélnek?'],
            eszkozok: ['Hogyan működik az IMEI-lekérdezés?', 'Mire jó a feloldó minta?'],
            penztar: ['Hogyan zárom a kasszát?', 'Miért nem egyezik a kassza?', 'Mi a mai forgalom?'],
            szolgaltatasok: ['Hogyan illesztem be az árlistát?', 'Mi a bevizsgálási díj?'],
            termekek: ['Hogyan kezelem a készletet?'],
            levelezes: ['Hogyan kötöm be a Gmailt?', 'Mit csinál a rendező ügynök?'],
            csapat: ['Hogyan küldök fontos üzenetet mindenkinek?', 'Hogyan veszek fel kollégát?'],
            arukiadas: ['Hogyan adok el valamit a pultnál?', 'Mennyi volt a mai árukiadás?'],
            rendelesek: ['Hogyan veszek fel rendelést?', 'Mi érkezett meg, amiről még nem szóltunk?'],
            naptar: ['Mi lesz ma a naptárban?', 'Hogyan állítok be ismétlődő eseményt?'],
            uzenetek: ['Hogyan lép be az ügyfél az ügyféloldalra?', 'Hol találom a QR-kódot?'],
            sugo: ['Hol kezdjem az ismerkedést?', 'Mi az újdonság a rendszerben?'],
            beallitasok: ['Mi nincs még beállítva?', 'Hogyan állítom be a nyitvatartást?'],
            asszisztens: ['Mit tudsz csinálni?', 'Hogyan állítok vissza egy műveletet?']
        }[m] || [];

        t = t.concat(['Vezess végig ezen az oldalon', 'Mi az újdonság a rendszerben?']).slice(0, 4);
        javaslatDoboz.innerHTML = t.map(function (s) {
            return '<button type="button" class="sdh-ai-chip" data-ai-chip="' + e(s) + '">' + e(s) + '</button>';
        }).join('');
        javaslatDoboz.hidden = false;
    }

    gyokerKattChip();

    function gyokerKattChip() {
        document.addEventListener('click', function (ev) {
            var chip = ev.target.closest && ev.target.closest('.sdh-ai-panel__javaslatok [data-ai-chip]');

            if (chip) {
                kuld(chip.getAttribute('data-ai-chip'));
            }
        });
    }

    /* ---- Az oldal „érzékelése": mi látszik, mi a hiba ---- */

    function cimke(el) {
        var t = el.getAttribute('aria-label') || '';

        if (!t && el.id) {
            var l = document.querySelector('label[for="' + (window.CSS && CSS.escape ? CSS.escape(el.id) : el.id) + '"]');

            t = l ? l.textContent : '';
        }

        if (!t && el.closest('label')) {
            t = el.closest('label').textContent;
        }

        // A mezők TARTALMA nem megy az asszisztenshez: szövegmezőnél és választónál csak a felirat számít.
        t = t || (/^(TEXTAREA|SELECT|INPUT)$/.test(el.tagName) ? '' : el.textContent) || el.getAttribute('placeholder') || el.getAttribute('title') || '';

        return t.replace(/\s+/g, ' ').trim().slice(0, 70);
    }

    function lathato(el) {
        if (!el.getClientRects().length) {
            return false;
        }

        var st = window.getComputedStyle(el);

        return st.visibility !== 'hidden' && st.display !== 'none';
    }

    function elemekGyujt() {
        var modal = legfelsoModal();
        var hatokor = modal || document;
        var sel = 'button, a.sdh-gomb, a.sdh-sav__link, a.sdh-cs-ful, [role="tab"], label.sdh-pt-pill, label.sdh-cs-pill, .sdh-fulek__fej label, input[type="text"], input[type="search"], input:not([type]), textarea, select, a[data-sdh-urlap], .sdh-nezetfulek a, .sdh-fejlec__gombok a';
        var ki = [];
        var n = 0;

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-ai]'), function (el) { el.removeAttribute('data-sdh-ai'); });

        Array.prototype.forEach.call(hatokor.querySelectorAll(sel), function (el) {
            if (ki.length >= 90 || el.closest('.sdh-ai, .sdh-cs-toastok, .sdh-ai-reflektor') || !lathato(el)) {
                return;
            }

            var c = cimke(el);

            if (!c) {
                return;
            }

            var id = 'e' + (++n);
            var tag = el.tagName.toLowerCase();
            var tipus = /input|textarea|select/.test(tag) ? 'mező' : (el.getAttribute('role') === 'tab' || el.closest('.sdh-fulek__fej, .sdh-nezetfulek, .sdh-cs-fulek') ? 'lapfül' : (tag === 'a' ? 'link' : 'gomb'));
            var hely = el.closest('dialog') ? 'ablakban' : (el.closest('.sdh-sav') ? 'oldalmenü' : (el.closest('.sdh-fej') ? 'fejléc' : ''));

            el.setAttribute('data-sdh-ai', id);
            ki.push({ id: id, cimke: c, tipus: tipus, hely: hely });
        });

        return ki;
    }

    function oldalAdat() {
        var modal = legfelsoModal();
        var ablakCim = modal ? modal.querySelector('.sdh-modal__cim, h2') : null;
        var hiba = (modal || document).querySelector('.sdh-modal__hiba, .sdh-uzenet--hiba');
        var cim = document.querySelector('.sdh-fejlec__cim, .sdh-fej__morzsa strong');

        return {
            kulcs: modulKulcs(),
            cim: cim ? cim.textContent.trim() : document.title.replace(/\s+–\s+SDH Műhely$/, ''),
            ablak: ablakCim ? ablakCim.textContent.trim() : '',
            hiba: hiba && lathato(hiba) ? hiba.textContent.trim().slice(0, 300) : ''
        };
    }

    /* ---- Kérdés küldése ---- */

    var gepelElem = null;

    function gepel(be) {
        if (be && !gepelElem) {
            gepelElem = document.createElement('div');
            gepelElem.className = 'sdh-ai-gepel';
            gepelElem.innerHTML = '<span></span><span></span><span></span><em>' + e(NEV) + ' gondolkodik…</em>';
            lista.appendChild(gepelElem);
            gorgetLe();
        } else if (!be && gepelElem) {
            gepelElem.remove();
            gepelElem = null;
        }

        var st = gyoker.querySelector('[data-ai-statusz]');

        st.textContent = be ? 'Gondolkodik…' : 'Itt vagyok, kérdezz bátran';
    }

    function kuld(szoveg, folytat) {
        szoveg = String(szoveg || '').trim();

        if ((!szoveg && !folytat) || fut) {
            return;
        }

        // „Vezess végig ezen az oldalon": az oldal kész túrája azonnal indul, API-hívás nélkül.
        if (szoveg && szoveg.length < 70 && /vezess\s+v[ée]gig|mutasd\s+be\s+(ezt\s+)?az?\s+oldal|oldalt[uú]r|k[öo]rbevezet/i.test(szoveg)) {
            mezo.value = '';
            mezo.style.height = 'auto';
            turaOldal().then(function (ment) {
                if (!ment && !tura) {
                    buborekMutat('Ezen az oldalon most nincs mit körbemutatnom. Kérdezz bátran – szóban is elmondom.', [], { ms: 6000 });
                }
            });

            return;
        }

        if (panel.hidden) {
            nyit();
        }

        fut = true;
        javaslatDoboz.hidden = true;

        if (szoveg) {
            uzenetRajzol({ szerep: 'user', szoveg: szoveg });
        }

        mezo.value = '';
        mezo.style.height = 'auto';
        gepel(true);
        allapot('gondolkodik');

        kerdes('ai_kerdez', {
            szoveg: szoveg,
            folytat: folytat ? '1' : '0',
            oldal: JSON.stringify(oldalAdat()),
            elemek: JSON.stringify(elemekGyujt())
        }, true).then(function (d) {
            gepel(false);

            var uz = uzenetRajzol({ szerep: 'assistant', szoveg: d.valasz, id: d.id });

            (d.ui || []).forEach(function (u) {
                if (u.tipus === 'megnyit' && u.adat && (u.adat.url || u.adat.popup)) {
                    uz.insertAdjacentHTML('beforeend', '<button type="button" class="sdh-ai-ugras" data-ai-ugras' +
                        (u.adat.popup ? ' data-popup="' + e(u.adat.popup) + '" data-id="' + (parseInt(u.adat.id, 10) || 0) + '"' : ' data-url="' + e(u.adat.url) + '"') +
                        '>' + IKON.nyil + e(u.adat.felirat) + '</button>');
                }
            });

            (d.kartyak || []).forEach(kartyaRajzol);
            gorgetLe();
            allapot((d.kartyak || []).length ? 'jelez' : 'beszel', 1800);

            // Végigvezetés: az asszisztens saját lépései (vezet), az oldal kész túrája (tura), vagy egyetlen megmutatás.
            var vezetes = (d.ui || []).filter(function (u) { return (u.tipus === 'vezet' || u.tipus === 'tura') && u.adat && (u.adat.lepesek || []).length; })[0];
            var mutatasok = (d.ui || []).filter(function (u) { return u.tipus === 'mutat'; });

            if (vezetes) {
                window.setTimeout(function () {
                    if (vezetes.tipus === 'tura') {
                        turaOldal();
                    } else if (!turaIndit(vezetes.adat.lepesek, { cim: vezetes.adat.cim || 'Végigvezetlek' })) {
                        uzenetRajzol({ szerep: 'hiba', szoveg: 'A lépések elemeit már nem látom a képernyőn – lehet, hogy közben bezárult egy ablak. Kérd újra, és megmutatom.' });
                    }
                }, 650);
            } else if (mutatasok.length) {
                window.setTimeout(function () { mutat(mutatasok[0].adat); }, 450);
            }
        }).catch(function (h) {
            gepel(false);
            uzenetRajzol({ szerep: 'hiba', szoveg: h.message });
            allapot('szomoru', 2500);
            gorgetLe();
        }).then(function () {
            fut = false;

            if (!panel.hidden) {
                mezo.focus();
            }
        });
    }

    /* ================================================================ */
    /* Végigvezetés (túra): lépésenként kiemel, a figura odarepül         */
    /* ================================================================ */

    /*
     * Egy túra lépések sora. Egy lépés vagy egy elemre mutat (szel: CSS-szelektor
     * a súgóból; elem: a látható elemek azonosítója, amit az asszisztens kapott),
     * vagy „szabad" (bevezető, zárás: csak buborék). A túra NEM tűnik el
     * kattintásra – a felhasználó közben dolgozhat; csak a Kilépés (vagy az Esc)
     * zárja be, illetve az utolsó lépés „Kész" gombja. Ha közben felugró ablak
     * nyílik, a túra megvárja, és onnan folytatja.
     */
    var tura = null;
    var turaDoboz = null;
    var turaKep = 0;

    function turaElem(l) {
        var el = null;

        if (l.elem) {
            el = document.querySelector('[data-sdh-ai="' + String(l.elem).replace(/[^a-z0-9]/gi, '') + '"]');
        } else if (l.szel) {
            try {
                Array.prototype.some.call(document.querySelectorAll(l.szel), function (x) {
                    if (!x.closest('.sdh-ai') && lathato(x)) {
                        el = x;

                        return true;
                    }

                    return false;
                });
            } catch (x) {
                el = null; // hibás szelektor a fejezetben: a lépés kimarad
            }
        }

        return el && lathato(el) ? el : null;
    }

    /**
     * @param {Array}  lepesek [{szel|elem|szabad, cim, szoveg}]
     * @param {Object} o       cim (a túra neve), leiras (a súgófejezet címe), egy (egyetlen megmutatás), csendes
     * @returns {boolean} elindult-e (hamis, ha egyetlen lépés eleme sincs a képernyőn)
     */
    function turaIndit(lepesek, o) {
        o = o || {};

        var lista = (lepesek || []).filter(function (l) { return l && (l.szabad || turaElem(l)); });

        if (!lista.some(function (l) { return !l.szabad; }) && !o.szabadIs) {
            return false;
        }

        turaVege(true);
        setalVege();
        buborekZar();
        kellek('');

        panel.classList.remove('is-latszik');
        panel.hidden = true;
        gyoker.classList.remove('is-nyitva');

        tura = { lepesek: lista, i: -1, cim: o.cim || '', leiras: o.leiras || '', egy: !!o.egy, szunet: false, el: null };

        turaDoboz = document.createElement('div');
        turaDoboz.className = 'sdh-ai-reflektor sdh-ai-tura';
        turaDoboz.setAttribute('role', 'dialog');
        turaDoboz.setAttribute('aria-label', 'Végigvezetés');
        turaDoboz.innerHTML = '<div class="sdh-ai-reflektor__gyuru"></div>' +
            '<div class="sdh-ai-reflektor__buborek" aria-live="polite">' +
            '<button type="button" class="sdh-ai__buborek-x" data-tura-ki aria-label="Kilépés a végigvezetésből" title="Kilépés (Esc)">' + IKON.x + '</button>' +
            '<p class="sdh-ai-tura__kalap"></p><h3 class="sdh-ai-tura__cim"></h3><div class="sdh-ai-tura__szoveg"></div>' +
            '<div class="sdh-ai-tura__pottyok" aria-hidden="true"></div>' +
            '<div class="sdh-ai-tura__gombok"></div></div>';
        gyoker.appendChild(turaDoboz);

        turaDoboz.addEventListener('click', function (ev) {
            var g = ev.target.closest && ev.target.closest('button, a');

            if (!g || !tura) {
                return;
            }

            if (g.hasAttribute('data-tura-ki')) {
                turaVege(false, false);
            } else if (g.hasAttribute('data-tura-elozo')) {
                turaLep(tura.i - 1, -1);
            } else if (g.hasAttribute('data-tura-kov')) {
                turaLep(tura.i + 1, 1);
            } else if (g.hasAttribute('data-tura-besz')) {
                turaVege(true);
                nyit();
            }
        });

        gyoker.classList.add('is-repul', 'is-tura');
        turaLep(0, 1);

        if (!o.egy && !o.csendes) {
            gesztus('integet', 1300);
        }

        return true;
    }

    /** Lépés a megadott sorszámra; ha az eleme közben eltűnt, továbblép az irányban. */
    function turaLep(i, irany) {
        if (!tura) {
            return;
        }

        var n = tura.lepesek.length;

        while (i >= 0 && i < n && !tura.lepesek[i].szabad && !turaElem(tura.lepesek[i])) {
            i += irany || 1;
        }

        if (i >= n) {
            turaVege(false, true);

            return;
        }

        if (i < 0) {
            return;
        }

        var l = tura.lepesek[i];
        var el = l.szabad ? null : turaElem(l);
        var utolso = i === n - 1;

        tura.i = i;
        tura.el = el;

        turaDoboz.classList.toggle('is-szabad', !el);
        turaDoboz.querySelector('.sdh-ai-tura__kalap').textContent = (tura.cim ? tura.cim + ' · ' : '') + (tura.egy ? 'megmutatom' : (i + 1) + ' / ' + n);
        turaDoboz.querySelector('.sdh-ai-tura__cim').textContent = l.cim || '';
        turaDoboz.querySelector('.sdh-ai-tura__cim').hidden = !l.cim;
        turaDoboz.querySelector('.sdh-ai-tura__szoveg').innerHTML = md(l.szoveg || 'Ide kattints!');

        var pottyok = '';
        var p;

        for (p = 0; p < n && n > 1 && n <= 16; p++) {
            pottyok += '<i class="' + (p < i ? 'is-kesz' : (p === i ? 'is-most' : '')) + '"></i>';
        }

        turaDoboz.querySelector('.sdh-ai-tura__pottyok').innerHTML = pottyok;

        var gombok = '';

        if (tura.egy) {
            gombok = '<button type="button" class="sdh-ai__buborek-gomb is-fo" data-tura-ki>Értem</button>' +
                '<button type="button" class="sdh-ai__buborek-gomb" data-tura-besz>Vissza a beszélgetéshez</button>';
        } else {
            gombok = (i > 0 ? '<button type="button" class="sdh-ai__buborek-gomb" data-tura-elozo>Előző</button>' : '') +
                '<button type="button" class="sdh-ai__buborek-gomb is-fo" data-tura-kov>' + (utolso ? 'Kész' : (i === 0 ? 'Mehet!' : 'Következő')) + '</button>' +
                (utolso && tura.leiras ? '<a class="sdh-ai__buborek-gomb" href="' + e(tura.leiras) + '">Részletes leírás</a>' : '');
        }

        turaDoboz.querySelector('.sdh-ai-tura__gombok').innerHTML = gombok;

        var bub = turaDoboz.querySelector('.sdh-ai-reflektor__buborek');

        bub.classList.remove('is-uj');
        void bub.offsetWidth;
        bub.classList.add('is-uj');

        if (el) {
            el.scrollIntoView({ block: 'center', behavior: nyugodtMozgas ? 'auto' : 'smooth' });
            allapot('mutat');
        } else {
            allapot(utolso ? 'orul' : 'beszel', 2200);
        }

        gesztus('bolint', 600);

        var fo = turaDoboz.querySelector('.sdh-ai__buborek-gomb.is-fo');

        if (fo && !tura.egy) {
            fo.focus({ preventScroll: true });
        }

        window.cancelAnimationFrame(turaKep);
        turaKovet();
    }

    /** A gyűrű, a buborék és a figura a kiemelt elemet követi (görgetés, átméretezés közben is). */
    function turaKovet() {
        if (!tura || !turaDoboz) {
            return;
        }

        var l = tura.lepesek[tura.i];
        var el = tura.el;
        var gy = turaDoboz.querySelector('.sdh-ai-reflektor__gyuru');
        var bub = turaDoboz.querySelector('.sdh-ai-reflektor__buborek');
        var modal = legfelsoModal();

        // Felugró ablak nyílt a kiemelt elem fölé: megvárjuk.
        if (el && modal && !modal.contains(el)) {
            if (!tura.szunet) {
                tura.szunet = true;
                turaDoboz.hidden = true;
                leny.style.transform = '';
                allapot('figyel');
                buborekMutat('Megvárlak! Ha bezártad az ablakot, onnan folytatjuk, ahol abbahagytuk.', [
                    { felirat: 'Kilépés a túrából', tesz: function () { turaVege(true); } }
                ], { ms: 0, allapot: 'figyel' });
            }

            turaKep = window.requestAnimationFrame(turaKovet);

            return;
        }

        if (tura.szunet) {
            tura.szunet = false;
            turaDoboz.hidden = false;
            buborekZar();
            allapot('mutat');
        }

        // Az elem közben eltűnt (pl. lapfülváltás): megpróbáljuk újra megkeresni, különben szabad lépésként áll.
        if (el && !lathato(el)) {
            el = tura.el = turaElem(l);
            turaDoboz.classList.toggle('is-szabad', !el);
        }

        var bw = Math.min(360, window.innerWidth - 32);
        var bx;
        var by;

        bub.style.width = bw + 'px';

        if (el) {
            var r = el.getBoundingClientRect();
            var pad = 6;
            // Nagy elemnél (pl. egy egész táblázat) a gyűrű a látható részére szorítkozik.
            var fent = Math.max(4, r.top - pad);
            var lent = Math.min(window.innerHeight - 4, r.bottom + pad);
            var bal = Math.max(4, r.left - pad);
            var jobb = Math.min(window.innerWidth - 4, r.right + pad);

            gy.style.left = bal + 'px';
            gy.style.top = fent + 'px';
            gy.style.width = Math.max(0, jobb - bal) + 'px';
            gy.style.height = Math.max(0, lent - fent) + 'px';

            var bh = bub.offsetHeight;
            var alatta = lent + 18 + bh < window.innerHeight - 8;
            var folotte = fent - 18 - bh > 8;

            bx = Math.max(16, Math.min(window.innerWidth - bw - 96, bal));

            if (alatta) {
                by = lent + 18;
            } else if (folotte) {
                by = fent - 18 - bh;
            } else {
                // Sem alatta, sem fölötte nincs hely (nagy elem): a buborék az elemen belül, alul áll.
                by = Math.max(8, window.innerHeight - bh - 24);
            }

            bub.classList.toggle('is-folotte', !alatta && folotte);
            bub.classList.toggle('is-benne', !alatta && !folotte);
        } else {
            // Szabad lépés: a buborék a képernyő közepén, a figura mellette.
            bx = Math.max(16, (window.innerWidth - bw) / 2 - 40);
            by = Math.max(16, (window.innerHeight - bub.offsetHeight) / 2);
            bub.classList.remove('is-folotte', 'is-benne');
        }

        bub.style.left = bx + 'px';
        bub.style.top = by + 'px';

        var lr = leny.getBoundingClientRect();

        if (!gyoker.dataset.alapX) {
            // A figura „otthona": innen számoljuk, hová kell repülnie.
            var mostani = new DOMMatrixReadOnly(window.getComputedStyle(leny).transform);

            gyoker.dataset.alapX = String(lr.left - mostani.m41);
            gyoker.dataset.alapY = String(lr.top - mostani.m42);
        }

        var cx = Math.min(window.innerWidth - lr.width - 12, bx + bw + 12);
        var cy = Math.max(10, Math.min(window.innerHeight - lr.height - 12, by - 6));

        leny.style.transform = 'translate(' + (cx - parseFloat(gyoker.dataset.alapX)).toFixed(0) + 'px,' + (cy - parseFloat(gyoker.dataset.alapY)).toFixed(0) + 'px)';

        turaKep = window.requestAnimationFrame(turaKovet);
    }

    /**
     * @param {boolean} csendes    ne ünnepeljen, ne szóljon (pl. másik túra indul)
     * @param {boolean} befejezve  végigért-e (igaz) vagy kilépett (hamis)
     */
    function turaVege(csendes, befejezve) {
        if (!tura) {
            return;
        }

        var volt = tura;

        window.cancelAnimationFrame(turaKep);

        if (turaDoboz) {
            turaDoboz.remove();
        }

        tura = null;
        turaDoboz = null;
        leny.style.transform = '';
        gyoker.classList.remove('is-repul', 'is-tura');
        delete gyoker.dataset.alapX;
        delete gyoker.dataset.alapY;
        allapot('nyugi');

        if (volt.szunet) {
            buborekZar();
        }

        if (csendes || volt.egy) {
            if (volt.egy && !csendes) {
                allapot('orul', 1200);
            }

            return;
        }

        if (befejezve) {
            window.setTimeout(function () {
                allapot('orul', 1800);
                gesztus('tancol', 1800);
                szikrak(12, 'konfetti');
                kellek('parti', 2600);

                if (HUMOR >= 15) {
                    beszol('turaVege', true);
                }
            }, 350);
        } else {
            window.setTimeout(function () {
                buborekMutat('Rendben, abbahagytam. Ha folytatnád, kérd bármikor: „Vezess végig ezen az oldalon”.', [], { ms: 6000 });
            }, 300);
        }
    }

    /* A túra billentyűi: jobbra / balra nyíl lépked, az Esc kilép (gépelés közben nem zavar). */
    document.addEventListener('keydown', function (ev) {
        if (!tura || tura.szunet) {
            return;
        }

        var cel = ev.target;
        var gepel = cel && (/^(INPUT|TEXTAREA|SELECT)$/.test(cel.tagName) || cel.isContentEditable);

        if (ev.key === 'Escape') {
            turaVege(false, false);
        } else if (!gepel && !tura.egy && ev.key === 'ArrowRight') {
            ev.preventDefault();
            turaLep(tura.i + 1, 1);
        } else if (!gepel && !tura.egy && ev.key === 'ArrowLeft') {
            ev.preventDefault();
            turaLep(tura.i - 1, -1);
        }
    });

    /** Egyetlen elem megmutatása (az asszisztens `mutat` eszköze). */
    function mutat(adat) {
        if (!turaIndit([{ elem: adat.elem, cim: '', szoveg: adat.szoveg || 'Ide kattints!' }], { egy: true })) {
            uzenetRajzol({ szerep: 'hiba', szoveg: 'Ezt az elemet már nem látom a képernyőn – lehet, hogy közben bezárult egy ablak.' });
        }
    }

    /* Az oldal általános részei – ezeket minden túra magától hozzáteszi (a fejezetnek nem kell leírnia). */
    var TURA_KERET = {
        menu: { szel: '.sdh-sav', cim: 'Az oldalmenü', szoveg: 'Innen érsz el mindent. A piros pötty és a szám azt jelzi, hol vár rád valami. A bal felső **≡** gombbal összecsukható, hogy több hely jusson a munkának.' },
        kereso: { szel: '.sdh-fej [data-sdh-kutat]', cim: 'Kereső – mindenhonnan', szoveg: 'Ügyfél, telefonszám, IMEI vagy gyári szám: kezdd el írni, és jön a találat. A **/** billentyűvel bárhonnan ide ugrasz.' },
        ora: { szel: '.sdh-fej [data-sdh-ora]', cim: 'Óra és zárásig hátralévő idő', szoveg: 'A pontos idő, alatta az, hogy mennyi van még zárásig. Zárás előtt színt vált, hogy ne érjen meglepetés.' },
        sugo: { szel: '.sdh-fej__sugo', cim: 'Súgó ehhez az oldalhoz', szoveg: 'Egy kattintás, és annak az oldalnak a részletes leírását kapod, ahol éppen állsz – kérdés–válaszokkal együtt.' },
        profil: { szel: '.sdh-profil__gomb', cim: 'A saját beállításaid', szoveg: 'Megjelenés (világos, sötét, **papír**), **kékfény-szűrő**, betűméret, a felugró ablakok kerete – mind csak a te gépedre szól. Itt tudsz kijelentkezni is.' }
    };
    var TURA_ALTALANOS = [
        { szel: '.sdh-fejlec__cim', cim: 'Hol vagy', szoveg: 'Az oldal neve – alatta egy mondat arról, mire való.' },
        { szel: '.sdh-fejlec__gombok', cim: 'A legfontosabb műveletek', szoveg: 'Az oldal fő gombjai. Ami adatot vesz fel, az felugró ablakban nyílik – az oldalt nem kell elhagynod.' },
        { szel: '.sdh-kereso', cim: 'Keresés és szűrés', szoveg: 'Itt szűkítheted a listát: írj be egy részletet, vagy válassz a szűrőkből.' },
        { szel: '.sdh-tabla', cim: 'A lista', szoveg: 'Egy sor egy tétel. A névre vagy a sor gombjára kattintva megnyílik a részletes ablak.' },
        { szel: '.sdh-lapozo, .sdh-cs-lapozo', cim: 'Lapozás', szoveg: 'Hosszú listánál nem görgetősáv van, hanem lapozó: a nyilakkal lépkedhetsz.' }
    ];
    var turaTolt = false;

    /**
     * Az éppen nyitott oldal túrája: a súgófejezet lépései, köré téve a bevezetőt,
     * az általános részeket és a zárást. API-kulcs nélkül is működik.
     */
    function turaOldal() {
        if (turaTolt || tura) {
            return Promise.resolve(false);
        }

        var m = modulKulcs();

        turaTolt = true;
        allapot('gondolkodik');

        return kerdes('ai_tura', { modul: m }, true).then(function (d) {
            var sajat = (d.lepesek || []).filter(function (l) { return turaElem(l); });
            var kozep = sajat.length ? d.lepesek : TURA_ALTALANOS;
            var nev = d.cim || (document.querySelector('.sdh-fejlec__cim') || {}).textContent || 'Ez az oldal';
            var lepesek = [{
                szabad: true,
                cim: nev,
                szoveg: (d.osszefoglalo ? d.osszefoglalo + ' ' : '') + 'Megmutatom, mi hol van. Lépkedj a **Következő** gombbal vagy a nyilakkal – közben nyugodtan kattints bármire, megvárlak. Kilépni az Esc-kel tudsz.'
            }];

            if (m === 'attekintes') {
                lepesek.push(TURA_KERET.menu);
            }

            lepesek = lepesek.concat(kozep);
            lepesek.push(TURA_KERET.kereso);

            if (m === 'attekintes') {
                lepesek.push(TURA_KERET.ora, TURA_KERET.profil);
            }

            lepesek.push(TURA_KERET.sugo, {
                szabad: true,
                cim: 'Ennyi volt!',
                szoveg: 'Most már ismered ezt az oldalt. Ha elakadsz, kattints rám, vagy nyomd meg az **F1**-et – a teljes leírásból és a rendszer mostani adataiból felelek.'
            });

            turaTolt = false;

            if (!turaIndit(lepesek, { cim: nev, leiras: d.leiras, szabadIs: true })) {
                allapot('nyugi');

                return false;
            }

            return true;
        }).catch(function (h) {
            turaTolt = false;
            allapot('szomoru', 2000);
            buborekMutat('A túrát most nem tudtam betölteni: ' + h.message, [], { ms: 7000 });

            return false;
        });
    }

    /** Régi név (más fájlok és az `is-repul` állapot kezelői hívják): mindent visszaállít. */
    function visszaRepul() {
        turaVege(true);
        leny.style.transform = '';
        gyoker.classList.remove('is-repul');
        delete gyoker.dataset.alapX;
        delete gyoker.dataset.alapY;
        allapot('nyugi');
    }

    /* ================================================================ */
    /* Érzékek: pulzus, hibák, köszönés                                  */
    /* ================================================================ */

    function pulzusKer() {
        if (window.SDH_CSAPAT && window.SDH_CSAPAT.pulzus) {
            window.SDH_CSAPAT.pulzus();
        }
    }

    document.addEventListener('sdh:pulzus', function (ev) {
        var ai = ev.detail && ev.detail.ai;

        if (!ai || !gyoker) {
            return;
        }

        var jel = gyoker.querySelector('.sdh-ai__jelveny');

        jel.hidden = !ai.fuggo;
        jel.textContent = ai.fuggo > 9 ? '9+' : String(ai.fuggo || '');

        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-jelveny="asszisztens"]'), function (j) {
            j.textContent = String(ai.fuggo || 0);
            j.setAttribute('data-db', String(ai.fuggo || 0));
            j.hidden = !ai.fuggo;
        });

        if (!panel.hidden || !buborek.hidden || gyoker.classList.contains('is-repul') || tura) {
            return;
        }

        setalVege();

        if (ai.ujdonsag && ai.ujdonsag.pontok && ai.ujdonsag.pontok.length) {
            var v = ai.ujdonsag.verzio;

            buborekMutat('**Frissültem (' + v + ')!** Ezt tanultam:\n\n' + ai.ujdonsag.pontok.map(function (p) { return '- ' + p; }).join('\n'), [
                { felirat: 'Meséld el', fo: true, tesz: function () { nyit('Mi az újdonság a ' + v + ' verzióban? Röviden, pontokban, és mondd meg, hol találom.'); } },
                { felirat: 'Később', tesz: function () {} }
            ], { allapot: 'orul', ms: 0 });

            return;
        }

        if (!A.proaktiv || Date.now() - utolsoTipp < 5 * 60000) {
            return;
        }

        var latott = tarOlvas('sdh-ai-latott', {});
        var nap = ma();
        var tipp = (ai.tippek || []).filter(function (t) { return latott[t.kulcs] !== nap; })[0];

        if (!tipp) {
            return;
        }

        latott[tipp.kulcs] = nap;
        tarIr('sdh-ai-latott', latott);
        utolsoTipp = Date.now();

        var gombok = [];

        if (tipp.gomb) {
            gombok.push({
                felirat: tipp.gomb.felirat,
                fo: true,
                tesz: function () {
                    if (tipp.gomb.url) {
                        window.location.href = tipp.gomb.url;
                    } else if (tipp.gomb.kerdes) {
                        nyit(tipp.gomb.kerdes);
                    } else {
                        nyit();
                    }
                }
            });
        }

        gombok.push({ felirat: 'Most nem', tesz: function () {} });
        buborekMutat(tipp.szoveg, gombok, { allapot: 'jelez' });
    });

    /* Ha egy űrlap hibát dob: felajánlja a segítséget. */
    var hibaLatott = {};

    new MutationObserver(function (valtozasok) {
        if (!gyoker || !A.proaktiv || !panel.hidden) {
            return;
        }

        valtozasok.forEach(function (v) {
            Array.prototype.forEach.call(v.addedNodes, function (n) {
                if (n.nodeType !== 1) {
                    return;
                }

                var h = n.matches('.sdh-modal__hiba, .sdh-uzenet--hiba') ? n : n.querySelector && n.querySelector('.sdh-modal__hiba');

                if (!h || h.closest('.sdh-ai, .sdh-cs-toastok')) {
                    return;
                }

                var szoveg = h.textContent.trim();

                if (!szoveg || hibaLatott[szoveg]) {
                    return;
                }

                hibaLatott[szoveg] = true;
                setalVege();
                allapot('ijedt', 1400);
                gesztus('razkodik', 600);
                window.setTimeout(function () {
                    if (tura) {
                        return;
                    }

                    buborekMutat('Hibát látok: „' + szoveg.slice(0, 140) + '”. Segítsek megoldani?', [
                        { felirat: 'Segíts', fo: true, tesz: function () { nyit('Ezt a hibaüzenetet kaptam: „' + szoveg.slice(0, 300) + '”. Mit csináljak?'); } },
                        { felirat: 'Megoldom', tesz: function () {} }
                    ], { allapot: 'jelez' });
                }, 700);
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });

    /* F1: súgó = az asszisztens. */
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'F1') {
            ev.preventDefault();

            if (panel.hidden) {
                nyit();
            } else {
                mezo.focus();
            }
        }

        if (ev.key === 'Escape' && gyoker && gyoker.classList.contains('is-repul') && !tura) {
            visszaRepul();
        }
    });

    /* Gomb bárhol: data-sdh-ai-nyit (pl. az Asszisztens oldal fejlécében). */
    document.addEventListener('click', function (ev) {
        var g = ev.target.closest && ev.target.closest('[data-sdh-ai-nyit]');

        if (g) {
            ev.preventDefault();
            nyit(g.getAttribute('data-sdh-ai-kerdes') || '');
        }
    });

    /* Indulás */
    function indit() {
        epit();

        // Naponta egyszer köszön.
        if (tarOlvas('sdh-ai-koszont', '') !== ma()) {
            tarIr('sdh-ai-koszont', ma());
            window.setTimeout(function () {
                if (panel.hidden && buborek.hidden) {
                    var nev = String(A.en || '').split(' ').pop();

                    buborekMutat('Szia' + (nev ? ' ' + nev : '') + '! Itt vagyok, ha kellek – kérdezz bármit a rendszerről. (F1)', [
                        { felirat: 'Kérdezek', fo: true, tesz: function () { nyit(); } }
                    ], { allapot: 'orul', ms: 9000 });
                }
            }, 2200);
        }

        // A Súgó „Vezess végig" gombja és a bemutató útvonala ?tura=1 címmel nyitja az oldalt: indul a túra.
        if (/[?&]tura=1(&|$)/.test(window.location.search)) {
            try {
                var tiszta = new URL(window.location.href);

                tiszta.searchParams.delete('tura');
                window.history.replaceState(null, '', tiszta.toString());
            } catch (x) { /* régi böngésző: a cím marad */ }

            window.setTimeout(function () {
                if (!buborek.hidden) {
                    buborekZar();
                }

                turaOldal();
            }, 900);
        }

        // buborek: rövid figyelmeztetés gombokkal (pl. a munkalap pipáinak levételekor – app.js: pipaSugo).
        // tura / vezet: az oldal kész túrája, illetve tetszőleges lépéssor (más modulok is hívhatják).
        window.SDH_AI_API = {
            nyit: nyit, kerdez: function (k) { nyit(k); }, mutat: mutat, buborek: buborekMutat,
            tura: turaOldal, vezet: turaIndit, turaVege: function () { turaVege(true); },
            hangulat: allapot, gesztus: gesztus, kellek: kellek, szikrak: szikrak
        };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', indit);
    } else {
        indit();
    }
}());
