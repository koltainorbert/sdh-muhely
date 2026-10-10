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
            '<span class="sdh-ai__test" aria-hidden="true"><span class="sdh-ai__mag"></span><span class="sdh-ai__fenyfolt"></span>' +
            '<span class="sdh-ai__arc"><span class="sdh-ai__szem sdh-ai__szem--b"><span class="sdh-ai__pupilla"></span></span>' +
            '<span class="sdh-ai__szem sdh-ai__szem--j"><span class="sdh-ai__pupilla"></span></span>' +
            '<span class="sdh-ai__pir sdh-ai__pir--b"></span><span class="sdh-ai__pir sdh-ai__pir--j"></span>' +
            '<span class="sdh-ai__szaj"></span></span></span>' +
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
            if (gyoker.classList.contains('is-repul')) {
                visszaRepul();

                return;
            }

            if (panel.hidden) {
                nyit();
            } else {
                zar();
            }
        });

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

    function allapot(nev, visszaMs) {
        if (!gyoker) {
            return;
        }

        window.clearTimeout(allapotIdo);
        gyoker.setAttribute('data-allapot', nev);

        if (visszaMs) {
            allapotIdo = window.setTimeout(function () { gyoker.setAttribute('data-allapot', fut ? 'gondolkodik' : 'nyugi'); }, visszaMs);
        }
    }

    /* ---- Élet: pislogás, szemmozgás, alvás ---- */

    function elet() {
        var szemek = gyoker.querySelectorAll('.sdh-ai__szem');
        var pupillak = gyoker.querySelectorAll('.sdh-ai__pupilla');
        var kep = 0;
        var cel = { x: 0, y: 0 };

        function pislog() {
            if (gyoker.getAttribute('data-allapot') !== 'alszik') {
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

            if (gyoker.getAttribute('data-allapot') === 'alszik') {
                allapot('orul', 1400);
            }

            cel.x = ev.clientX;
            cel.y = ev.clientY;

            if (kep) {
                return;
            }

            kep = window.requestAnimationFrame(function () {
                kep = 0;

                var r = leny.getBoundingClientRect();
                var dx = cel.x - (r.left + r.width / 2);
                var dy = cel.y - (r.top + r.height / 2);
                var tav = Math.sqrt(dx * dx + dy * dy) || 1;
                var m = Math.min(1, tav / 260) * 3.2;

                Array.prototype.forEach.call(pupillak, function (p) {
                    p.style.transform = 'translate(' + (dx / tav * m).toFixed(2) + 'px,' + (dy / tav * m).toFixed(2) + 'px)';
                });
            });
        }, { passive: true });

        ['keydown', 'click', 'scroll'].forEach(function (es) {
            document.addEventListener(es, function () {
                utolsoMozgas = Date.now();

                if (gyoker.getAttribute('data-allapot') === 'alszik') {
                    allapot('nyugi');
                }
            }, { passive: true, capture: true });
        });

        window.setInterval(function () {
            var all = gyoker.getAttribute('data-allapot');

            if (Date.now() - utolsoMozgas > 4 * 60000 && all === 'nyugi' && panel.hidden) {
                allapot('alszik');
            }
        }, 15000);
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

        t = t || el.textContent || el.getAttribute('placeholder') || el.getAttribute('title') || '';

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

            var mutatasok = (d.ui || []).filter(function (u) { return u.tipus === 'mutat'; });

            if (mutatasok.length) {
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
    /* Megmutatás: kiemelés + a figura odarepül                           */
    /* ================================================================ */

    var reflektor = null;
    var reflektorKep = 0;
    var reflektorElem = null;

    function mutat(adat) {
        var el = document.querySelector('[data-sdh-ai="' + String(adat.elem || '').replace(/[^a-z0-9]/gi, '') + '"]');

        if (!el || !lathato(el)) {
            uzenetRajzol({ szerep: 'hiba', szoveg: 'Ezt az elemet már nem látom a képernyőn – lehet, hogy közben bezárult egy ablak.' });

            return;
        }

        reflektorZar();
        reflektorElem = el;
        el.scrollIntoView({ block: 'center', behavior: 'smooth' });

        panel.classList.remove('is-latszik');
        panel.hidden = true;

        reflektor = document.createElement('div');
        reflektor.className = 'sdh-ai-reflektor';
        reflektor.innerHTML = '<div class="sdh-ai-reflektor__gyuru"></div>' +
            '<div class="sdh-ai-reflektor__buborek"><p>' + md(adat.szoveg || 'Ide kattints!') + '</p>' +
            '<div><button type="button" class="sdh-ai__buborek-gomb is-fo" data-ai-ertem>Értem</button>' +
            '<button type="button" class="sdh-ai__buborek-gomb" data-ai-vissza-besz>Vissza a beszélgetéshez</button></div></div>';
        gyoker.appendChild(reflektor);

        reflektor.querySelector('[data-ai-ertem]').addEventListener('click', function () {
            reflektorZar();
            visszaRepul();
        });
        reflektor.querySelector('[data-ai-vissza-besz]').addEventListener('click', function () {
            reflektorZar();
            visszaRepul();
            nyit();
        });

        // A kiemelt elemre kattintva is elengedi.
        el.addEventListener('click', kiemeltKatt, { once: true });

        gyoker.classList.add('is-repul');
        allapot('mutat');
        reflektorKovet();
    }

    function kiemeltKatt() {
        reflektorZar();
        visszaRepul();
        allapot('orul', 1500);
    }

    function reflektorKovet() {
        if (!reflektor || !reflektorElem) {
            return;
        }

        var r = reflektorElem.getBoundingClientRect();
        var gy = reflektor.querySelector('.sdh-ai-reflektor__gyuru');
        var bub = reflektor.querySelector('.sdh-ai-reflektor__buborek');
        var pad = 6;

        gy.style.left = (r.left - pad) + 'px';
        gy.style.top = (r.top - pad) + 'px';
        gy.style.width = (r.width + pad * 2) + 'px';
        gy.style.height = (r.height + pad * 2) + 'px';

        // A buborék az elem alá (ha nem fér, fölé), a figura a buborék mellé.
        var bw = Math.min(320, window.innerWidth - 32);
        var lent = r.bottom + 150 < window.innerHeight;
        var bx = Math.max(16, Math.min(window.innerWidth - bw - 90, r.left));
        var by = lent ? r.bottom + 18 : Math.max(16, r.top - 18 - bub.offsetHeight);

        bub.style.width = bw + 'px';
        bub.style.left = bx + 'px';
        bub.style.top = by + 'px';
        bub.classList.toggle('is-folotte', !lent);

        var lr = leny.getBoundingClientRect();
        var alapX = gyoker.dataset.alapX ? parseFloat(gyoker.dataset.alapX) : lr.left;
        var alapY = gyoker.dataset.alapY ? parseFloat(gyoker.dataset.alapY) : lr.top;

        if (!gyoker.dataset.alapX) {
            gyoker.dataset.alapX = String(lr.left);
            gyoker.dataset.alapY = String(lr.top);
        }

        var cx = Math.min(window.innerWidth - 80, bx + bw + 12);
        var cy = Math.max(10, Math.min(window.innerHeight - 80, by - 10));

        leny.style.transform = 'translate(' + (cx - alapX).toFixed(0) + 'px,' + (cy - alapY).toFixed(0) + 'px)';

        reflektorKep = window.requestAnimationFrame(reflektorKovet);
    }

    function reflektorZar() {
        window.cancelAnimationFrame(reflektorKep);

        if (reflektorElem) {
            reflektorElem.removeEventListener('click', kiemeltKatt);
        }

        if (reflektor) {
            reflektor.remove();
        }

        reflektor = null;
        reflektorElem = null;
    }

    function visszaRepul() {
        reflektorZar();
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

        if (!panel.hidden || !buborek.hidden || gyoker.classList.contains('is-repul')) {
            return;
        }

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
                window.setTimeout(function () {
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

        if (ev.key === 'Escape' && gyoker && gyoker.classList.contains('is-repul')) {
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

        window.SDH_AI_API = { nyit: nyit, kerdez: function (k) { nyit(k); }, mutat: mutat };
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', indit);
    } else {
        indit();
    }
}());
