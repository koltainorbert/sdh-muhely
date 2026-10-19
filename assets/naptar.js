/*
 * SDH Műhely – Naptár (0.50).
 *
 *  1. MINDEN oldalon: az esedékes események kártyái. A pulzus (csapat.js,
 *     15 másodpercenként) hozza a ki nem nyomott jelzéseket; a kártya addig
 *     marad a képernyőn – oldalváltás után is –, amíg kézzel ki nem nyomják.
 *  2. A Naptár oldalon: a nézetek (ma, nap, 3 nap, munkahét, hét, hónap,
 *     negyedév, év, napirend, idővonal, személyek, tábla), a nevek naptárai,
 *     a visszaszámlálás, az esemény űrlapjának viselkedése, húzással
 *     áthelyezés és átméretezés.
 *
 * Az időpontok „falióra-idők": a szerver a WordPress időzónája szerinti
 * helyi időt küldi (éééé-hh-nnTóó:pp), a böngésző ezt nem váltja át. A
 * „most" is a szerveré – a böngésző csak lépteti –, így rosszul járó gépen
 * is ugyanazt mutatja a visszaszámláló mindenkinél.
 *
 * Görgetősáv nincs: minden nézet kitölti a rendelkezésre álló magasságot,
 * ami nem fér ki, az lapozható vagy „+N" mögött áll.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};

    if (!B.ajax) {
        return;
    }

    /* ================================================================ */
    /* Segédek                                                           */
    /* ================================================================ */

    var NAPOK = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
    var NAPOK_R = ['V', 'H', 'K', 'Sze', 'Cs', 'P', 'Szo'];
    var HONAPOK = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
    var HONAPOK_R = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
    var PERC = 60000;
    var NAP_MS = 86400000;

    var IKON = {
        x: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8"/></svg>',
        bal: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M10 3.5 5.5 8l4.5 4.5"/></svg>',
        jobb: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M6 3.5 10.5 8 6 12.5"/></svg>',
        ism: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 8a5 5 0 0 1 8.6-3.5L13 6M13 8a5 5 0 0 1-8.6 3.5L3 10"/><path d="M13 3v3h-3M3 13v-3h3"/></svg>',
        level: '<svg viewBox="0 0 16 16" aria-hidden="true"><rect x="2" y="3.5" width="12" height="9" rx="1.5"/><path d="M2.5 4.5 8 8.8l5.5-4.3"/></svg>',
        zar: '<svg viewBox="0 0 16 16" aria-hidden="true"><rect x="3.5" y="7" width="9" height="6.5" rx="1.4"/><path d="M5.5 7V5.2a2.5 2.5 0 0 1 5 0V7"/></svg>',
        ora: '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="5.6"/><path d="M8 4.8V8l2.2 1.4"/></svg>',
        hely: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 14s4.2-3.8 4.2-7A4.2 4.2 0 0 0 3.8 7c0 3.2 4.2 7 4.2 7z"/><circle cx="8" cy="6.8" r="1.5"/></svg>',
        ceruza: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3 13l.6-2.7 6.7-6.7a1.4 1.4 0 0 1 2 0l.1.1a1.4 1.4 0 0 1 0 2L5.7 12.4z"/></svg>',
        plusz: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 3.2v9.6M3.2 8h9.6"/></svg>',
        pipa: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.4l3 3 6-6.6"/></svg>',
        csillag: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.8l1.2 3.6 3.6 1.2-3.6 1.2L8 11.4 6.8 7.8 3.2 6.6l3.6-1.2zM12.5 10.5l.6 1.6 1.6.6-1.6.6-.6 1.6-.6-1.6-1.6-.6 1.6-.6z"/></svg>',
        naptar: '<svg viewBox="0 0 16 16" aria-hidden="true"><rect x="2.4" y="3.4" width="11.2" height="10.2" rx="1.5"/><path d="M2.4 6.6h11.2M5.4 2v2.6M10.6 2v2.6"/></svg>',
        fel: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 10 8 5.5l4.5 4.5"/></svg>',
        le: '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 6 8 10.5 12.5 6"/></svg>'
    };

    function e(szoveg) {
        return String(szoveg === null || szoveg === undefined ? '' : szoveg)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function p2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    /** 'éééé-hh-nn' vagy 'éééé-hh-nnTóó:pp' → Date (falióra-idő). */
    function datum(szoveg) {
        var m = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(String(szoveg || ''));

        return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), 0, 0) : new Date(NaN);
    }

    function iso(d) {
        return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate());
    }

    function isoIdo(d) {
        return iso(d) + 'T' + p2(d.getHours()) + ':' + p2(d.getMinutes());
    }

    function ido(d) {
        return p2(d.getHours()) + ':' + p2(d.getMinutes());
    }

    function napEleje(d) {
        return new Date(d.getFullYear(), d.getMonth(), d.getDate());
    }

    function plusz(d, napok) {
        var x = new Date(d.getTime());

        x.setDate(x.getDate() + napok);

        return x;
    }

    function pluszHo(d, honapok) {
        return new Date(d.getFullYear(), d.getMonth() + honapok, 1);
    }

    function hetfo(d) {
        var x = napEleje(d);

        return plusz(x, -((x.getDay() + 6) % 7));
    }

    function ugyanNap(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function napSzoveg(d, rovid) {
        return d.getFullYear() + '. ' + (rovid ? HONAPOK_R[d.getMonth()] : HONAPOK[d.getMonth()]) + ' ' + d.getDate() + '., ' + NAPOK[d.getDay()];
    }

    function hetSzama(d) {
        var x = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
        var n = x.getUTCDay() || 7;

        x.setUTCDate(x.getUTCDate() + 4 - n);

        return Math.ceil(((x - Date.UTC(x.getUTCFullYear(), 0, 1)) / NAP_MS + 1) / 7);
    }

    function ker(akcio, adat) {
        var fd = new FormData();

        fd.set('action', 'sdh_muhely_' + akcio);
        fd.set('_wpnonce', B.nonce || '');

        Object.keys(adat || {}).forEach(function (k) {
            fd.set(k, adat[k]);
        });

        return fetch(B.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (v) {
                // Nem JSON válasz (szerverhiba, lejárt belépés, kiírt PHP-figyelmeztetés): érthető üzenet a „JSON.parse" helyett.
                return v.text().then(function (szoveg) {
                    var t = String(szoveg || '');
                    var hol = t.indexOf('{"success"');

                    try {
                        return JSON.parse(t);
                    } catch (h) {
                        if (hol > 0) {
                            try { return JSON.parse(t.slice(hol)); } catch (h2) { /* lent */ }
                        }
                    }

                    var tiszta = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240);

                    throw new Error(v.status >= 400
                        ? 'A szerver hibát adott (' + v.status + ')' + (tiszta ? ': ' + tiszta : '.')
                        : (tiszta === '0' || tiszta === '-1' ? 'Lejárt a belépés – töltsd újra az oldalt.' : 'A szerver nem várt választ adott' + (tiszta ? ': ' + tiszta : '.')));
                });
            })
            .then(function (v) {
                if (!v || !v.success) {
                    throw new Error((v && v.data && v.data.uzenet) || 'A művelet nem sikerült.');
                }

                return v.data;
            });
    }

    /* ---- A szerver órája: a böngésző csak lépteti ---- */

    var szerverMost = null;     // Date (falióra-idő), amikor a szerver utoljára megmondta
    var helyiAkkor = 0;         // Date.now() ugyanakkor

    function mostBeallit(szoveg) {
        var d = datum(szoveg);

        if (!isNaN(d.getTime())) {
            szerverMost = d;
            helyiAkkor = Date.now();
        }
    }

    function most() {
        return szerverMost ? new Date(szerverMost.getTime() + (Date.now() - helyiAkkor)) : new Date();
    }

    /* ---- Visszaszámlálás ---- */

    function tartam(ms, mpvel) {
        var mp = Math.max(0, Math.floor(ms / 1000));
        var nap = Math.floor(mp / 86400);
        var ora = Math.floor((mp % 86400) / 3600);
        var perc = Math.floor((mp % 3600) / 60);

        if (nap > 0) {
            return nap + ' nap' + (ora > 0 ? ' ' + ora + ' óra' : '');
        }

        if (ora > 0) {
            return ora + ' óra' + (perc > 0 ? ' ' + perc + ' perc' : '');
        }

        if (perc > 0) {
            return perc + ' perc' + (mpvel && perc < 10 ? ' ' + p2(mp % 60) + ' mp' : '');
        }

        return mpvel ? (mp % 60) + ' mp' : 'kevesebb mint 1 perc';
    }

    /** „2 óra 14 perc múlva" / „most zajlik – még 25 perc" / „véget ért 3 órája". */
    function vissza(k, v, m) {
        if (m < k) {
            return { szoveg: tartam(k - m, true) + ' múlva', allapot: (k - m) < 15 * PERC ? 'hamar' : 'elott' };
        }

        if (m < v) {
            return { szoveg: 'most zajlik – még ' + tartam(v - m, false), allapot: 'most' };
        }

        // Múlt: csak a legnagyobb egység („3 órája", „2 napja").
        var perc = Math.floor((m - v) / PERC);
        var regen = perc < 1 ? 'épp most' : (perc < 60 ? perc + ' perce' : (perc < 1440 ? Math.floor(perc / 60) + ' órája' : Math.floor(perc / 1440) + ' napja'));

        return { szoveg: 'véget ért ' + regen, allapot: 'utan' };
    }

    function visszaFrissit(gyoker) {
        var m = most();

        Array.prototype.forEach.call((gyoker || document).querySelectorAll('[data-np-vissza]'), function (elem) {
            var k = datum(elem.getAttribute('data-k'));
            var v = datum(elem.getAttribute('data-v'));

            if (isNaN(k.getTime())) {
                return;
            }

            // Egész napos eseménynél a vég a nap vége; hiányzó végnél a kezdet.
            var r = vissza(k, isNaN(v.getTime()) || v < k ? k : v, m);

            if (elem.textContent !== r.szoveg) {
                elem.textContent = r.szoveg;
            }

            elem.setAttribute('data-allapot', r.allapot);
        });
    }

    window.setInterval(function () { visszaFrissit(document); }, 1000);

    function visszaHtml(k, v, osztaly) {
        return '<span class="sdh-np-vissza' + (osztaly ? ' ' + osztaly : '') + '" data-np-vissza data-k="' + e(k) + '" data-v="' + e(v) + '"></span>';
    }

    /* ================================================================ */
    /* 1. Jelzések – minden oldalon                                      */
    /* ================================================================ */

    var jelzesDoboz = null;
    var jelzesUrl = '';
    var jelzesIsmert = {};
    var jelzesLista = [];
    var MAX_KARTYA = 3;

    /*
     * A kártyák összecsukhatók egy kis jelzővé (hogy ne takarják, amin épp
     * dolgoznak) – ettől még nem tűnnek el: a jelző ott marad, amíg minden
     * kártyát ki nem nyomtak. Új esedékes eseménynél a kártyák újra kinyílnak.
     */
    function csukvaEddig() {
        try {
            return parseInt(window.sessionStorage.getItem('sdh-np-csukva'), 10) || 0;
        } catch (x) {
            return 0;
        }
    }

    function csukvaIr(id) {
        try {
            window.sessionStorage.setItem('sdh-np-csukva', String(id));
        } catch (x) { /* privát ablak */ }
    }

    function jelveny(db) {
        Array.prototype.forEach.call(document.querySelectorAll('[data-sdh-jelveny="naptar"]'), function (jel) {
            jel.setAttribute('data-db', String(db));
            jel.textContent = db > 99 ? '99+' : String(db);
            jel.title = db + ' esedékes esemény';
            jel.hidden = db <= 0;
        });
    }

    function jelzesRajzol(lista) {
        lista = lista || [];
        jelzesLista = lista;
        jelveny(lista.length);

        if (!jelzesDoboz) {
            jelzesDoboz = document.createElement('div');
            jelzesDoboz.className = 'sdh-np-jelzesek';
            jelzesDoboz.setAttribute('aria-live', 'polite');
            jelzesDoboz.setAttribute('aria-label', 'Esedékes események');
            document.body.appendChild(jelzesDoboz);
        }

        var html = '';
        var legujabb = lista.reduce(function (max, j) { return Math.max(max, j.id); }, 0);

        if (lista.length && legujabb <= csukvaEddig()) {
            jelzesDoboz.innerHTML = '<button type="button" class="sdh-np-jelzo' + (lista.some(function (j) { return j.pri === 'kritikus'; }) ? ' is-pri-kritikus' : '') + '" data-np-kinyit title="Az esedékes események megmutatása">' +
                IKON.naptar + '<b>' + lista.length + '</b><span>esedékes</span></button>';
            jelzesDoboz.hidden = false;
            jelzesDoboz.classList.add('is-csukva');

            return;
        }

        jelzesDoboz.classList.remove('is-csukva');

        lista.slice(0, MAX_KARTYA).forEach(function (j) {
            var uj = !jelzesIsmert[j.id];

            jelzesIsmert[j.id] = true;

            html +=
                '<div class="sdh-np-jelzes sdh-np-szin--' + e(j.szin) + ' is-pri-' + e(j.pri) + (uj ? ' is-uj' : '') + '" data-np-jelzes="' + j.id + '" role="alert">' +
                '<span class="sdh-np-jelzes__jel" aria-hidden="true">' + IKON.naptar + '</span>' +
                '<div class="sdh-np-jelzes__torzs">' +
                '<p class="sdh-np-jelzes__kalap">' + (j.pri === 'kritikus' ? 'Nagyon fontos' : (j.pri === 'fontos' ? 'Fontos esemény' : 'Esedékes esemény')) +
                (j.szemely ? ' · ' + e(j.szemely) : '') + '</p>' +
                '<strong class="sdh-np-jelzes__cim">' + e(j.cim) + '</strong>' +
                '<span class="sdh-np-jelzes__mikor">' + e(j.mikor) + (j.hely ? ' · ' + e(j.hely) : '') + '</span>' +
                visszaHtml(j.k, j.v, 'sdh-np-jelzes__vissza') +
                '<span class="sdh-np-jelzes__gombok">' +
                '<button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-np-kinyom="' + j.id + '">Kinyomom</button>' +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-np-megnyit="' + j.esemeny + '" data-datum="' + e(j.k.slice(0, 10)) + '">Megnyitás</button>' +
                '</span></div></div>';
        });

        if (lista.length) {
            html += '<div class="sdh-np-jelzes sdh-np-jelzes--tobb"><span>' +
                (lista.length > MAX_KARTYA ? '+' + (lista.length - MAX_KARTYA) + ' további esedékes esemény' : lista.length + ' esedékes esemény') + '</span>' +
                '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-np-csuk title="Kis jelzővé csukja – a kártyák megmaradnak, amíg ki nem nyomod őket">Összecsuk</button>' +
                (lista.length > 1 ? '<button type="button" class="sdh-gomb sdh-gomb--vilagos" data-np-kinyom-mind>Mind kinyomása</button>' : '') + '</div>';
        }

        jelzesDoboz.innerHTML = html;
        jelzesDoboz.hidden = lista.length === 0;
        visszaFrissit(jelzesDoboz);
    }

    document.addEventListener('sdh:pulzus', function (ev) {
        var n = ev.detail && ev.detail.naptar;

        if (!n) {
            return;
        }

        mostBeallit(n.most);
        jelzesUrl = n.url || jelzesUrl;
        jelzesRajzol(n.jelzesek);
    });

    document.addEventListener('click', function (ev) {
        var cel = ev.target.closest ? ev.target.closest('[data-np-kinyom], [data-np-kinyom-mind], [data-np-megnyit], [data-np-csuk], [data-np-kinyit]') : null;

        if (!cel || !jelzesDoboz || !jelzesDoboz.contains(cel)) {
            return;
        }

        ev.preventDefault();

        if (cel.hasAttribute('data-np-csuk') || cel.hasAttribute('data-np-kinyit')) {
            csukvaIr(cel.hasAttribute('data-np-csuk') ? jelzesLista.reduce(function (max, j) { return Math.max(max, j.id); }, 0) : 0);
            jelzesRajzol(jelzesLista);

            return;
        }

        if (cel.hasAttribute('data-np-megnyit')) {
            var id = cel.getAttribute('data-np-megnyit');
            var nap = cel.getAttribute('data-datum');

            if (oldal) {
                oldal.ugras(datum(nap));
                esemenyNyit(id, nap);
            } else if (jelzesUrl) {
                window.location.href = jelzesUrl + (jelzesUrl.indexOf('?') >= 0 ? '&' : '?') + 'datum=' + encodeURIComponent(nap) + '&esemeny=' + encodeURIComponent(id);
            }

            return;
        }

        cel.disabled = true;

        ker('naptar_kinyom', cel.hasAttribute('data-np-kinyom-mind') ? { mind: '1' } : { id: cel.getAttribute('data-np-kinyom') })
            .then(function (d) {
                jelzesRajzol(d.jelzesek);

                if (oldal) {
                    oldal.jelzesDb(d.db);
                }
            })
            .catch(function () { cel.disabled = false; });
    });

    /* ================================================================ */
    /* Esemény megnyitása (popup) és az űrlap viselkedése                */
    /* ================================================================ */

    function esemenyNyit(id, alkalom, parameterek) {
        if (!window.SDH_MUHELY_APP) {
            return;
        }

        var p = parameterek || {};

        if (alkalom) {
            p.alkalom = alkalom;
        }

        window.SDH_MUHELY_APP.nyit('naptar', String(id || '0'), { parameterek: p });
    }

    function urlapIgazit(urlap) {
        var egesz = urlap.querySelector('[data-sdh-np-egesz]');

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-np-ido]'), function (idoMezo) {
            idoMezo.hidden = !!(egesz && egesz.checked);
        });

        var ism = urlap.querySelector('[data-sdh-np-ismetles]');
        var fajta = ism ? ism.value : '';
        var egyseg = urlap.querySelector('[data-sdh-np-ism-egyseg]');
        var mod = urlap.querySelector('[data-sdh-np-ism-mod] input:checked');
        var modErtek = mod ? mod.value : 'soha';

        Array.prototype.forEach.call(urlap.querySelectorAll('[data-sdh-np-ism-resz]'), function (resz) {
            resz.hidden = fajta === '';
        });

        var napok = urlap.querySelector('[data-sdh-np-ism-napok]');

        if (napok) {
            napok.hidden = fajta !== 'heti';
        }

        if (egyseg) {
            egyseg.textContent = { napi: 'napon', heti: 'héten', havi: 'hónapban', eves: 'évben' }[fajta] || '';
        }

        var veg = urlap.querySelector('[data-sdh-np-ism-veg]');
        var db = urlap.querySelector('[data-sdh-np-ism-db]');

        if (veg) {
            veg.hidden = fajta === '' || modErtek !== 'datum';
        }

        if (db) {
            db.hidden = fajta === '' || modErtek !== 'db';
        }

        Array.prototype.forEach.call(urlap.querySelectorAll('.sdh-np-pillek .sdh-cs-pill'), function (pill) {
            var be = pill.querySelector('input');

            pill.classList.toggle('is-aktiv', !!(be && be.checked));
        });
    }

    function urlapIdo(urlap, elotag) {
        var nap = urlap.querySelector('[name="' + elotag + '_datum"]');
        var ora = urlap.querySelector('[name="' + elotag + '_ora"]');
        var perc = urlap.querySelector('[name="' + elotag + '_perc"]');

        return nap && nap.value ? datum(nap.value + 'T' + (ora ? ora.value : '00') + ':' + (perc ? perc.value : '00')) : new Date(NaN);
    }

    function urlapIdoBe(urlap, elotag, d) {
        var nap = urlap.querySelector('[name="' + elotag + '_datum"]');
        var ora = urlap.querySelector('[name="' + elotag + '_ora"]');
        var perc = urlap.querySelector('[name="' + elotag + '_perc"]');

        if (nap) { nap.value = iso(d); }
        if (ora) { ora.value = p2(d.getHours()); }
        if (perc) { perc.value = p2(Math.floor(d.getMinutes() / 5) * 5); }
    }

    document.addEventListener('sdh:urlap-betoltve', function (ev) {
        var torzs = ev.detail && ev.detail.torzs;
        var urlap = torzs ? torzs.querySelector('[data-sdh-np-urlap]') : null;

        if (!urlap) {
            if (torzs && torzs.querySelector('.sdh-np-urlap--szemely')) {
                urlapIgazit(torzs.querySelector('.sdh-np-urlap--szemely'));
            }

            return;
        }

        // A kezdet és a vég távolsága: a kezdet átírásakor a vég vele megy.
        var hossz = urlapIdo(urlap, 'veg') - urlapIdo(urlap, 'kezdet');

        urlap.sdhHossz = isNaN(hossz) || hossz <= 0 ? 60 * PERC : hossz;
        urlapIgazit(urlap);

        var visszaElem = urlap.querySelector('[data-sdh-np-vissza]');

        if (visszaElem) {
            visszaElem.innerHTML = visszaHtml(visszaElem.getAttribute('data-kezdet'), visszaElem.getAttribute('data-veg'));
            visszaFrissit(visszaElem);
        }
    });

    function urlapValtozas(ev) {
        var urlap = ev.target.closest ? ev.target.closest('.sdh-np-urlap') : null;

        if (!urlap) {
            return;
        }

        var nev = ev.target.getAttribute('name') || '';

        if (urlap.hasAttribute('data-sdh-np-urlap')) {
            if (/^kezdet_/.test(nev)) {
                var k = urlapIdo(urlap, 'kezdet');

                if (!isNaN(k.getTime())) {
                    urlapIdoBe(urlap, 'veg', new Date(k.getTime() + (urlap.sdhHossz || 60 * PERC)));
                }
            } else if (/^veg_/.test(nev)) {
                var k2 = urlapIdo(urlap, 'kezdet');
                var v2 = urlapIdo(urlap, 'veg');

                if (!isNaN(k2.getTime()) && !isNaN(v2.getTime())) {
                    if (v2 <= k2) {
                        // A vég nem lehet a kezdet előtt: a nap marad, az idő egy órával a kezdet után.
                        var veg = urlap.querySelector('[name="veg_datum"]');

                        if (veg && veg.value < iso(k2)) {
                            veg.value = iso(k2);
                        }
                    } else {
                        urlap.sdhHossz = v2 - k2;
                    }
                }
            }
        }

        urlapIgazit(urlap);
    }

    document.addEventListener('change', urlapValtozas);

    /* Törlés: kétlépcsős gomb (a böngésző felugró kérdése helyett). */
    document.addEventListener('click', function (ev) {
        var gomb = ev.target.closest ? ev.target.closest('[data-sdh-np-torol]') : null;

        if (!gomb) {
            return;
        }

        ev.preventDefault();

        var urlap = gomb.closest('form');

        if (!gomb.classList.contains('is-biztos')) {
            gomb.setAttribute('data-felirat', gomb.textContent);
            gomb.textContent = 'Biztos? Kattints újra';
            gomb.classList.add('is-biztos');

            window.setTimeout(function () {
                if (gomb.isConnected && gomb.classList.contains('is-biztos')) {
                    gomb.textContent = gomb.getAttribute('data-felirat');
                    gomb.classList.remove('is-biztos');
                }
            }, 4000);

            return;
        }

        gomb.disabled = true;

        ker('naptar_torol', {
            id: urlap.querySelector('[name="id"]').value,
            mod: gomb.getAttribute('data-sdh-np-torol'),
            alkalom: urlap.querySelector('[name="alkalom"]').value
        }).then(function () {
            var megsem = urlap.querySelector('[data-sdh-megsem]');

            if (megsem) {
                megsem.click();
            }

            if (oldal) {
                oldal.tolt();
            }
        }).catch(function (hiba) {
            gomb.disabled = false;
            gomb.textContent = hiba.message;
        });
    });

    /* Mentés után a naptár helyben frissül – nincs oldalváltás. */
    document.addEventListener('sdh:mentve', function (ev) {
        var akcio = ev.detail && ev.detail.action;

        if (!oldal || (akcio !== 'sdh_muhely_naptar_ment' && akcio !== 'sdh_muhely_naptarszemely_ment')) {
            return;
        }

        ev.preventDefault();
        oldal.tolt();
    });

    /* ================================================================ */
    /* 2. A Naptár oldal                                                 */
    /* ================================================================ */

    var oldal = null;
    var gyoker = document.querySelector('[data-sdh-naptar]');

    if (!gyoker) {
        return;
    }

    var C = {};

    try {
        C = JSON.parse(gyoker.getAttribute('data-sdh-naptar') || '{}');
    } catch (x) {
        C = {};
    }

    var keret = gyoker.querySelector('[data-sdh-np-keret]');
    var oldalsav = gyoker.querySelector('[data-sdh-np-oldal]');
    var sav = gyoker.querySelector('[data-sdh-np-sav]');
    var nezetHely = gyoker.querySelector('[data-sdh-np-nezet]');

    function tarol(kulcs, ertek) {
        try {
            if (ertek === undefined) {
                return window.localStorage.getItem('sdh-np-' + kulcs);
            }

            window.localStorage.setItem('sdh-np-' + kulcs, ertek);
        } catch (x) { /* privát ablak */ }

        return null;
    }

    var A = {
        nezet: C.nezetek && C.nezetek[tarol('nezet')] ? tarol('nezet') : (C.alapNezet || 'het'),
        datum: C.datum ? datum(C.datum) : napEleje(new Date()),
        mini: null,                 // a kis naptár hónapja
        szurt: null,                // kijelölt nevek (null = mind)
        teljes: tarol('teljes') === '1',
        ivMod: tarol('ivmod') === 'nap' ? 'nap' : 'het',     // az idővonal léptéke: egy nap órái vagy a hét napjai
        kereses: '',
        lap: 0,                     // lapozás a listás nézetekben
        adat: null,
        tolt: 0
    };

    if (C.datum && A.nezet === 'ma') {
        A.nezet = 'nap';
    }

    try {
        var mentettSzurt = JSON.parse(tarol('szurt') || 'null');

        A.szurt = Array.isArray(mentettSzurt) && mentettSzurt.length ? mentettSzurt.map(Number) : null;
    } catch (x2) {
        A.szurt = null;
    }

    A.mini = new Date(A.datum.getFullYear(), A.datum.getMonth(), 1);

    /* ---- Időszak nézetenként ---- */

    function idoszak() {
        var d = napEleje(A.datum);
        var h = hetfo(d);

        switch (A.nezet) {
            case 'ma':
                return { tol: napEleje(most()), ig: plusz(napEleje(most()), 2) };
            case 'nap':
            case 'szemelyek':
                return { tol: d, ig: plusz(d, 1) };
            case 'idovonal':
                return A.ivMod === 'nap' ? { tol: d, ig: plusz(d, 1) } : { tol: h, ig: plusz(h, 7) };
            case 'haromnap':
                return { tol: d, ig: plusz(d, 3) };
            case 'munkahet':
                return { tol: h, ig: plusz(h, 5) };
            case 'het':
            case 'tabla':
                return { tol: h, ig: plusz(h, 7) };
            case 'honap':
                var elso = hetfo(new Date(d.getFullYear(), d.getMonth(), 1));

                return { tol: elso, ig: plusz(elso, 42) };
            case 'negyedev':
                var q = Math.floor(d.getMonth() / 3) * 3;

                return { tol: hetfo(new Date(d.getFullYear(), q, 1)), ig: plusz(hetfo(new Date(d.getFullYear(), q + 3, 1)), 7) };
            case 'ev':
                return { tol: new Date(d.getFullYear(), 0, 1), ig: new Date(d.getFullYear() + 1, 0, 1) };
            default: // napirend
                return { tol: d, ig: plusz(d, 30) };
        }
    }

    function lep(irany) {
        var d = A.datum;

        switch (A.nezet) {
            case 'ma':
                // A „Ma" mindig a mai nap: léptetve a szomszéd nap napi nézete nyílik.
                A.nezet = 'nap';
                A.datum = plusz(napEleje(most()), irany);
                break;
            case 'nap':
            case 'szemelyek':
                A.datum = plusz(d, irany);
                break;
            case 'idovonal':
                A.datum = plusz(d, (A.ivMod === 'nap' ? 1 : 7) * irany);
                break;
            case 'haromnap':
                A.datum = plusz(d, 3 * irany);
                break;
            case 'honap':
                A.datum = pluszHo(d, irany);
                break;
            case 'negyedev':
                A.datum = pluszHo(d, 3 * irany);
                break;
            case 'ev':
                A.datum = new Date(d.getFullYear() + irany, d.getMonth(), 1);
                break;
            case 'napirend':
                A.datum = plusz(d, 30 * irany);
                break;
            default:
                A.datum = plusz(d, 7 * irany);
        }

        A.lap = 0;
        A.mini = new Date(A.datum.getFullYear(), A.datum.getMonth(), 1);
    }

    function cim() {
        var d = A.datum;
        var r = idoszak();
        var utolso = plusz(r.ig, -1);

        switch (A.nezet) {
            case 'ma':
                return 'Ma – ' + napSzoveg(napEleje(most()));
            case 'nap':
            case 'szemelyek':
                return napSzoveg(d);
            case 'idovonal':
                return A.ivMod === 'nap' ? napSzoveg(d) : hetCim(r, utolso, true);
            case 'honap':
                return d.getFullYear() + '. ' + HONAPOK[d.getMonth()];
            case 'negyedev':
                return d.getFullYear() + '. ' + ['I.', 'II.', 'III.', 'IV.'][Math.floor(d.getMonth() / 3)] + ' negyedév';
            case 'ev':
                return String(d.getFullYear());
            default:
                return hetCim(r, utolso, A.nezet !== 'napirend' && A.nezet !== 'haromnap');
        }
    }

    function hetCim(r, utolso, hettel) {
        var eleje = r.tol.getFullYear() + '. ' + HONAPOK[r.tol.getMonth()] + ' ' + r.tol.getDate() + '.';
        var vege = (utolso.getMonth() !== r.tol.getMonth() ? HONAPOK[utolso.getMonth()] + ' ' : '') + utolso.getDate() + '.';

        return eleje + ' – ' + vege + (hettel ? ' · ' + hetSzama(r.tol) + '. hét' : '');
    }

    /* ---- Adatok ---- */

    function szemely(id) {
        var lista = (A.adat && A.adat.szemelyek) || [];

        for (var i = 0; i < lista.length; i++) {
            if (lista[i].id === id) {
                return lista[i];
            }
        }

        return null;
    }

    /** A megjelenítendő nevek: a kijelöltek (a listában álló sorrendben), különben mind. */
    function lathatoSzemelyek() {
        var lista = (A.adat && A.adat.szemelyek) || [];

        if (!A.szurt) {
            return lista;
        }

        var szurt = lista.filter(function (s) { return A.szurt.indexOf(s.id) >= 0; });

        return szurt.length ? szurt : lista;
    }

    function esemenyek() {
        var q = A.kereses.toLowerCase();
        var lista = (A.adat && A.adat.esemenyek) || [];

        if (!q) {
            return lista;
        }

        return lista.filter(function (ev) {
            return (ev.cim + ' ' + ev.hely + ' ' + ev.leiras + ' ' + ev.ugyfel).toLowerCase().indexOf(q) >= 0;
        });
    }

    function elokeszit(lista) {
        (lista || []).forEach(function (ev) {
            ev.K = datum(ev.k);
            ev.V = datum(ev.v);
            // Sávban (a napok fölött) áll: az egész napos és a legalább egy napig tartó esemény.
            ev.sav = ev.en || (ev.V - ev.K) >= NAP_MS;
        });

        return lista || [];
    }

    function tolt() {
        var r = idoszak();
        var sorszam = ++A.tolt;

        gyoker.classList.add('is-tolt');

        return ker('naptar_adat', {
            tol: iso(r.tol),
            ig: iso(r.ig),
            szemelyek: A.szurt ? A.szurt.join(',') : '',
            ma: A.nezet === 'ma' ? '1' : '0'
        }).then(function (d) {
            if (sorszam !== A.tolt) {
                return;
            }

            mostBeallit(d.most);
            elokeszit(d.esemenyek);
            elokeszit(d.kovetkezo);
            elokeszit(d.lemaradt);
            A.adat = d;
            gyoker.classList.remove('is-tolt');
            rajzol();
        }).catch(function (hiba) {
            if (sorszam === A.tolt) {
                gyoker.classList.remove('is-tolt');
                nezetHely.innerHTML = '<div class="sdh-uzenet sdh-uzenet--hiba">A naptár nem töltődött be. ' + e(hiba.message) + '</div>';
            }
        });
    }

    /* ---- Esemény-elemek ---- */

    function jelek(ev) {
        return (ev.pri === 'kritikus' ? '<i class="sdh-np-jel sdh-np-jel--kritikus" title="Nagyon fontos">!!</i>' : (ev.pri === 'fontos' ? '<i class="sdh-np-jel sdh-np-jel--fontos" title="Fontos">!</i>' : '')) +
            (ev.ism ? '<i class="sdh-np-jel" title="Ismétlődő">' + IKON.ism + '</i>' : '') +
            (ev.email ? '<i class="sdh-np-jel" title="E-mail értesítés megy róla">' + IKON.level + '</i>' : '') +
            (ev.privat ? '<i class="sdh-np-jel" title="Privát">' + IKON.zar + '</i>' : '');
    }

    function osztalyok(ev) {
        return 'sdh-np-szin--' + e(ev.szin) + ' is-' + e(ev.all) + ' is-pri-' + e(ev.pri) + (ev.szerk ? '' : ' is-zart');
    }

    function sugo(ev) {
        var sz = szemely(ev.sz);

        return ev.cim + '\n' + (ev.en ? 'egész nap' : ido(ev.K) + '–' + ido(ev.V)) + (ev.hely ? '\n' + ev.hely : '') + (sz ? '\n' + sz.nev : '') +
            (ev.ugyfel ? '\nÜgyfél: ' + ev.ugyfel : '') + (ev.ml ? '\nMunkalap: ' + ev.ml : '');
    }

    function attr(ev) {
        return ' data-np-ev="' + ev.id + '" data-np-alk="' + e(ev.k.slice(0, 10)) + '" title="' + e(sugo(ev)) + '"';
    }

    /** Rövid „csík": hónap, sáv, idővonal. */
    function csik(ev, idovel) {
        return '<button type="button" class="sdh-np-csik ' + osztalyok(ev) + '"' + attr(ev) + (ev.szerk && !ev.ism ? ' draggable="true"' : '') + '>' +
            (idovel && !ev.en ? '<b>' + ido(ev.K) + '</b>' : '') + '<span>' + e(ev.cim) + '</span>' + jelek(ev) + '</button>';
    }

    function szemelyPotty(id) {
        var sz = szemely(id);

        return sz ? '<i class="sdh-np-potty sdh-np-szin--' + e(sz.szin) + '" title="' + e(sz.nev) + '"></i>' : '';
    }

    /* ---- Oldalsáv: kis naptár, nevek, következő események ---- */

    function miniNaptar() {
        var elso = hetfo(A.mini);
        var ma = napEleje(most());
        var r = idoszak();
        var html = '<div class="sdh-np-mini"><div class="sdh-np-mini__fej">' +
            '<button type="button" data-np-mini="-1" aria-label="Előző hónap">' + IKON.bal + '</button>' +
            '<strong>' + A.mini.getFullYear() + '. ' + HONAPOK[A.mini.getMonth()] + '</strong>' +
            '<button type="button" data-np-mini="1" aria-label="Következő hónap">' + IKON.jobb + '</button></div><div class="sdh-np-mini__racs">';

        [1, 2, 3, 4, 5, 6, 0].forEach(function (n) {
            html += '<span class="sdh-np-mini__nap">' + NAPOK_R[n] + '</span>';
        });

        // Mely napokon van esemény (a betöltött időszakból).
        var van = {};

        esemenyek().forEach(function (ev) {
            var d = napEleje(ev.K);

            while (d <= ev.V) {
                van[iso(d)] = true;
                d = plusz(d, 1);
            }
        });

        for (var i = 0; i < 42; i++) {
            var d = plusz(elso, i);

            html += '<button type="button" class="sdh-np-mini__d' + (d.getMonth() !== A.mini.getMonth() ? ' is-mas' : '') + (ugyanNap(d, ma) ? ' is-ma' : '') +
                (d >= r.tol && d < r.ig && A.nezet !== 'ma' ? ' is-latszik' : '') + (van[iso(d)] ? ' is-van' : '') + '" data-np-nap="' + iso(d) + '">' + d.getDate() + '</button>';
        }

        return html + '</div></div>';
    }

    function oldalsavRajzol() {
        var d = A.adat || {};
        var html = miniNaptar();

        html += '<div class="sdh-np-blokk"><p class="sdh-np-blokk__cim">Naptárak' +
            (C.szemelySzerk ? '<button type="button" class="sdh-np-kisgomb" data-np-szemely="0" title="Új név – új naptár" aria-label="Új név – új naptár">' + IKON.plusz + '</button>' : '') +
            '</p><div class="sdh-np-nevek">';

        (d.szemelyek || []).forEach(function (sz) {
            var be = !A.szurt || A.szurt.indexOf(sz.id) >= 0;

            html += '<div class="sdh-np-nev' + (be ? ' is-be' : '') + '">' +
                '<button type="button" class="sdh-np-nev__gomb" data-np-szur="' + sz.id + '" aria-pressed="' + (be ? 'true' : 'false') + '" title="' + e(sz.nev) + ' naptárának mutatása / elrejtése">' +
                '<i class="sdh-np-nev__jel sdh-np-szin--' + e(sz.szin) + '">' + (be ? IKON.pipa : '') + '</i><span>' + e(sz.nev) + (sz.id === d.sajat ? ' <em>(én)</em>' : '') + '</span></button>' +
                (C.szemelySzerk ? '<button type="button" class="sdh-np-kisgomb" data-np-szemely="' + sz.id + '" title="Szerkesztés" aria-label="' + e(sz.nev) + ' szerkesztése">' + IKON.ceruza + '</button>' : '') +
                '</div>';
        });

        html += '</div>';

        if ((d.szemelyek || []).length > 1) {
            html += '<div class="sdh-np-nevek__gombok"><button type="button" data-np-szur="mind">Mindenki</button>' +
                (d.sajat ? '<button type="button" data-np-szur="' + d.sajat + '" data-np-csak>Csak én</button>' : '') + '</div>';
        }

        html += '</div><div class="sdh-np-blokk sdh-np-blokk--kov"><p class="sdh-np-blokk__cim">Következő</p><div class="sdh-np-kov">';

        var kov = (d.kovetkezo || []).slice(0, 5);

        kov.forEach(function (ev) {
            html += '<button type="button" class="sdh-np-kov__elem ' + osztalyok(ev) + '"' + attr(ev) + '>' +
                '<strong>' + e(ev.cim) + '</strong>' +
                '<span>' + e(ugyanNap(ev.K, most()) ? 'ma' : HONAPOK_R[ev.K.getMonth()] + ' ' + ev.K.getDate() + '.') + (ev.en ? ', egész nap' : ' ' + ido(ev.K)) + '</span>' +
                visszaHtml(ev.k, ev.v) + '</button>';
        });

        if (!kov.length) {
            html += '<p class="sdh-np-ures">Nincs közelgő esemény.</p>';
        }

        html += '</div></div>';

        if (C.beallitasUrl) {
            html += '<a class="sdh-np-beall" href="' + e(C.beallitasUrl) + '">A naptár beállításai</a>';
        }

        oldalsav.innerHTML = html;
        levag(oldalsav.querySelector('.sdh-np-kov'), '.sdh-np-kov__elem');
        visszaFrissit(oldalsav);
    }

    /* ---- Felső sáv ---- */

    function savRajzol() {
        var racsos = ['nap', 'haromnap', 'munkahet', 'het', 'szemelyek'].indexOf(A.nezet) >= 0 || (A.nezet === 'idovonal' && A.ivMod === 'nap');
        var html = '<div class="sdh-np-sav__bal">' +
            '<button type="button" class="sdh-np-nyil" data-np-lep="-1" aria-label="Előző">' + IKON.bal + '</button>' +
            '<button type="button" class="sdh-np-magomb" data-np-ma>Ma</button>' +
            '<button type="button" class="sdh-np-nyil" data-np-lep="1" aria-label="Következő">' + IKON.jobb + '</button>' +
            '<h2 class="sdh-np-sav__cim">' + e(cim()) + '</h2></div>' +
            '<div class="sdh-np-sav__jobb">' +
            (A.nezet === 'idovonal' ? '<span class="sdh-np-ketto" role="group" aria-label="Az idővonal léptéke">' +
                '<button type="button" class="' + (A.ivMod === 'nap' ? 'is-be' : '') + '" data-np-ivmod="nap">Nap órái</button>' +
                '<button type="button" class="' + (A.ivMod === 'het' ? 'is-be' : '') + '" data-np-ivmod="het">Hét napjai</button></span>' : '') +
            (racsos ? '<button type="button" class="sdh-np-kapcsolo' + (A.teljes ? ' is-be' : '') + '" data-np-teljes aria-pressed="' + (A.teljes ? 'true' : 'false') + '" title="Az egész nap (0–24 óra) vagy csak a beállított órasáv">0–24</button>' : '') +
            '<input type="search" class="sdh-np-kereso" placeholder="Keresés az eseményekben…" value="' + e(A.kereses) + '" data-np-kereso aria-label="Keresés az eseményekben">' +
            '</div><div class="sdh-np-nezetek" role="tablist" aria-label="Nézetek">';

        Object.keys(C.nezetek || {}).forEach(function (k) {
            html += '<button type="button" role="tab" class="sdh-np-nezetgomb' + (k === A.nezet ? ' is-aktiv' : '') + '" aria-selected="' + (k === A.nezet ? 'true' : 'false') + '" data-np-nezet="' + e(k) + '">' + e(C.nezetek[k]) + '</button>';
        });

        // A kereső ne veszítse el a fókuszt újrarajzoláskor.
        var fokusz = document.activeElement && document.activeElement.hasAttribute && document.activeElement.hasAttribute('data-np-kereso');
        var hely = fokusz ? document.activeElement.selectionStart : 0;

        sav.innerHTML = html + '</div>';

        if (fokusz) {
            var mezo = sav.querySelector('[data-np-kereso]');

            mezo.focus();
            mezo.setSelectionRange(hely, hely);
        }
    }

    /* ================================================================ */
    /* Nézetek                                                           */
    /* ================================================================ */

    function magassag() {
        // A nézet a képernyő aljáig ér: így semmi nem görget.
        var felso = keret.getBoundingClientRect().top + window.scrollY;
        var torzs = keret.closest('.sdh-app__torzs');
        // A tartalomterület alsó térköze is beleszámít: az oldal így nem lesz hosszabb a képernyőnél.
        var also = torzs ? parseFloat(window.getComputedStyle(torzs).paddingBottom) || 0 : 18;
        var h = Math.max(460, window.innerHeight - felso - also - 2);

        keret.style.height = h + 'px';
    }

    /** A betűnagyítás szorzója (admin.css: --sdh-n): a sorok magassága ezzel együtt nő. */
    function nagyitas() {
        return parseFloat(window.getComputedStyle(document.documentElement).getPropertyValue('--sdh-n')) || 1;
    }

    function orak() {
        var tol = A.teljes ? 0 : (C.oraTol || 7);
        var ig = A.teljes ? 24 : (C.oraIg || 19);

        return { tol: tol, ig: Math.max(tol + 2, ig) };
    }

    /** Átfedő események hasábokba rendezése egy napon belül. */
    function hasabok(lista) {
        lista.sort(function (a, b) { return a.tolP - b.tolP || b.igP - a.igP; });

        var csoport = [];
        var csoportVeg = -1;

        function lezar() {
            var hasabVeg = [];

            csoport.forEach(function (x) {
                var h = 0;

                while (h < hasabVeg.length && hasabVeg[h] > x.tolP) {
                    h++;
                }

                hasabVeg[h] = x.igP;
                x.hasab = h;
            });

            csoport.forEach(function (x) { x.hasabDb = hasabVeg.length; });
            csoport = [];
        }

        lista.forEach(function (x) {
            if (csoport.length && x.tolP >= csoportVeg) {
                lezar();
                csoportVeg = -1;
            }

            csoport.push(x);
            csoportVeg = Math.max(csoportVeg, x.igP);
        });

        if (csoport.length) {
            lezar();
        }

        return lista;
    }

    /**
     * Időrács: oszloponként egy nap (vagy a „Személyek" nézetben egy név).
     * oszlopok: [{ nap: Date, szemely: id|0, fej: html }]
     */
    function idoracs(oszlopok) {
        var o = orak();
        var ossz = (o.ig - o.tol) * 60;
        var lista = esemenyek();
        var m = most();
        var html = '<div class="sdh-np-ir" style="--np-oszlop:' + oszlopok.length + '"><div class="sdh-np-ir__fej"><span class="sdh-np-ir__sarok"></span>';

        oszlopok.forEach(function (oszl) {
            html += '<div class="sdh-np-ir__fejcella' + (ugyanNap(oszl.nap, m) ? ' is-ma' : '') + '">' + oszl.fej + '</div>';
        });

        // Sáv: egész napos és több napos események.
        html += '</div><div class="sdh-np-ir__sav"><span class="sdh-np-ir__sarok">egész nap</span>';

        oszlopok.forEach(function (oszl, i) {
            var nap = oszl.nap;
            var holnap = plusz(nap, 1);
            var savos = lista.filter(function (ev) {
                return ev.sav && ev.K < holnap && ev.V >= nap && (!oszl.szemely || ev.sz === oszl.szemely || ev.resz.indexOf(oszl.szemely) >= 0);
            });

            html += '<div class="sdh-np-ir__savcella" data-np-cella="' + iso(nap) + '" data-np-egesz="1" data-np-oszlop="' + i + '"' + (oszl.szemely ? ' data-np-szemely="' + oszl.szemely + '"' : '') + '>' +
                savos.slice(0, 3).map(function (ev) { return csik(ev, false); }).join('') +
                (savos.length > 3 ? '<button type="button" class="sdh-np-tobb" data-np-nap="' + iso(nap) + '" data-np-napra>+' + (savos.length - 3) + '</button>' : '') + '</div>';
        });

        html += '</div><div class="sdh-np-ir__torzs"><div class="sdh-np-ir__orak">';

        for (var h = o.tol; h < o.ig; h++) {
            html += '<span style="top:' + ((h - o.tol) * 60 / ossz * 100) + '%">' + p2(h) + ':00</span>';
        }

        html += '</div>';

        oszlopok.forEach(function (oszl, i) {
            var nap = oszl.nap;
            var holnap = plusz(nap, 1);
            var fent = 0;
            var lent = 0;
            var blokkok = [];

            lista.forEach(function (ev) {
                if (ev.sav || ev.K >= holnap || ev.V <= nap || (oszl.szemely && ev.sz !== oszl.szemely && ev.resz.indexOf(oszl.szemely) < 0)) {
                    return;
                }

                // A napra eső rész percben, a látható órasávra vágva.
                var tolP = Math.max(0, (ev.K - nap) / PERC) - o.tol * 60;
                var igP = Math.min(1440, (ev.V - nap) / PERC) - o.tol * 60;

                if (igP <= 0) { fent++; return; }
                if (tolP >= ossz) { lent++; return; }

                blokkok.push({ ev: ev, tolP: Math.max(0, tolP), igP: Math.max(Math.max(0, tolP) + 20, Math.min(ossz, igP)) });
            });

            hasabok(blokkok);

            html += '<div class="sdh-np-ir__oszlop' + (ugyanNap(nap, m) ? ' is-ma' : '') + '" data-np-cella="' + iso(nap) + '" data-np-oszlop="' + i + '"' + (oszl.szemely ? ' data-np-szemely="' + oszl.szemely + '"' : '') + '>';

            for (var hh = o.tol; hh < o.ig; hh++) {
                html += '<i class="sdh-np-ir__vonal" style="top:' + ((hh - o.tol) * 60 / ossz * 100) + '%"></i>';
            }

            blokkok.forEach(function (bl) {
                var ev = bl.ev;
                var rovid = (bl.igP - bl.tolP) < 45;

                html += '<div class="sdh-np-blokkev ' + osztalyok(ev) + (rovid ? ' is-rovid' : '') + '"' + attr(ev) + (ev.szerk && !ev.ism ? ' data-np-huz' : '') +
                    ' style="top:' + (bl.tolP / ossz * 100) + '%;height:' + ((bl.igP - bl.tolP) / ossz * 100) + '%;left:calc(' + (bl.hasab / bl.hasabDb * 100) + '% + 2px);width:calc(' + (100 / bl.hasabDb) + '% - 4px)" tabindex="0" role="button">' +
                    '<span class="sdh-np-blokkev__ido">' + (!oszl.szemely && lathatoSzemelyek().length > 1 ? szemelyPotty(ev.sz) : '') + ido(ev.K) + '–' + ido(ev.V) + jelek(ev) + '</span>' +
                    '<strong>' + e(ev.cim) + '</strong>' +
                    (ev.hely && !rovid ? '<span class="sdh-np-blokkev__hely">' + e(ev.hely) + '</span>' : '') +
                    (ev.szerk && !ev.ism ? '<i class="sdh-np-blokkev__fogo" data-np-meretez aria-hidden="true"></i>' : '') + '</div>';
            });

            if (fent) {
                html += '<button type="button" class="sdh-np-kivul sdh-np-kivul--fent" data-np-teljes title="Korábbi esemény – az egész nap mutatása">' + IKON.fel + fent + '</button>';
            }

            if (lent) {
                html += '<button type="button" class="sdh-np-kivul sdh-np-kivul--lent" data-np-teljes title="Későbbi esemény – az egész nap mutatása">' + IKON.le + lent + '</button>';
            }

            if (ugyanNap(nap, m)) {
                html += '<i class="sdh-np-most" data-np-most data-tol="' + o.tol + '" data-ossz="' + ossz + '"><b></b></i>';
            }

            html += '</div>';
        });

        return html + '</div></div>';
    }

    function mostVonal() {
        var m = most();

        Array.prototype.forEach.call(nezetHely.querySelectorAll('[data-np-most]'), function (vonal) {
            var perc = m.getHours() * 60 + m.getMinutes() - (+vonal.getAttribute('data-tol')) * 60;
            var ossz = +vonal.getAttribute('data-ossz');

            vonal.hidden = perc < 0 || perc > ossz;
            vonal.style.top = (perc / ossz * 100) + '%';
            vonal.querySelector('b').textContent = ido(m);
        });
    }

    window.setInterval(mostVonal, 30000);

    function napFej(d) {
        return '<button type="button" class="sdh-np-napfej" data-np-nap="' + iso(d) + '" data-np-napra><span>' + NAPOK[d.getDay()] + '</span><b>' + d.getDate() + '</b><em>' + HONAPOK_R[d.getMonth()] + '</em></button>';
    }

    function nezetNapok(db, eleje) {
        var oszlopok = [];

        for (var i = 0; i < db; i++) {
            oszlopok.push({ nap: plusz(eleje, i), szemely: 0, fej: napFej(plusz(eleje, i)) });
        }

        return idoracs(oszlopok);
    }

    function nezetSzemelyek() {
        var nap = napEleje(A.datum);

        return idoracs(lathatoSzemelyek().map(function (sz) {
            return { nap: nap, szemely: sz.id, fej: '<span class="sdh-np-szemfej"><i class="sdh-np-avatar sdh-np-szin--' + e(sz.szin) + '">' + e(sz.betu) + '</i><b>' + e(sz.nev) + '</b></span>' };
        }));
    }

    /* ---- Hónap ---- */

    function nezetHonap() {
        var r = idoszak();
        var m = most();
        var lista = esemenyek();
        var html = '<div class="sdh-np-ho"><div class="sdh-np-ho__fej"><span class="sdh-np-ho__het"></span>';

        [1, 2, 3, 4, 5, 6, 0].forEach(function (n) { html += '<span>' + NAPOK[n] + '</span>'; });

        html += '</div><div class="sdh-np-ho__racs">';

        for (var w = 0; w < 6; w++) {
            html += '<div class="sdh-np-ho__sor"><button type="button" class="sdh-np-ho__het" data-np-nap="' + iso(plusz(r.tol, w * 7)) + '" data-np-hetre title="A hét megnyitása">' + hetSzama(plusz(r.tol, w * 7)) + '</button>';

            for (var i = 0; i < 7; i++) {
                var d = plusz(r.tol, w * 7 + i);
                var holnap = plusz(d, 1);
                var napi = lista.filter(function (ev) { return ev.K < holnap && ev.V >= d && (ev.V > d || ev.K >= d); });

                html += '<div class="sdh-np-ho__cella' + (d.getMonth() !== A.datum.getMonth() ? ' is-mas' : '') + (ugyanNap(d, m) ? ' is-ma' : '') + (d.getDay() === 0 || d.getDay() === 6 ? ' is-hetvege' : '') + '" data-np-cella="' + iso(d) + '" data-np-egesz="1">' +
                    '<button type="button" class="sdh-np-ho__szam" data-np-nap="' + iso(d) + '" data-np-napra>' + d.getDate() + (d.getDate() === 1 ? ' <em>' + HONAPOK_R[d.getMonth()] + '</em>' : '') + '</button>' +
                    '<div class="sdh-np-ho__lista" data-np-lista data-db="' + napi.length + '">' +
                    napi.map(function (ev) { return csik(ev, true); }).join('') +
                    '<button type="button" class="sdh-np-tobb" data-np-nap="' + iso(d) + '" data-np-napra hidden></button></div></div>';
            }

            html += '</div>';
        }

        return html + '</div></div>';
    }

    /** A hónap celláiban csak annyi esemény látszik, amennyi kifér; a többi „+N" mögött. */
    function honapIgazit() {
        Array.prototype.forEach.call(nezetHely.querySelectorAll('[data-np-lista]'), function (lista) {
            var csikok = lista.querySelectorAll('.sdh-np-csik');
            var tobb = lista.querySelector('.sdh-np-tobb');

            if (!csikok.length) {
                return;
            }

            Array.prototype.forEach.call(csikok, function (c) { c.hidden = false; });
            tobb.hidden = true;

            var sor = csikok[0].getBoundingClientRect().height + 2;
            var fer = Math.max(1, Math.floor((lista.clientHeight + 2) / sor));

            if (csikok.length <= fer) {
                return;
            }

            var latszik = Math.max(0, fer - 1);

            Array.prototype.forEach.call(csikok, function (c, i) { c.hidden = i >= latszik; });
            tobb.hidden = false;
            tobb.textContent = '+' + (csikok.length - latszik) + ' további';
        });
    }

    /**
     * Görgetés helyett: a tárolóból hátulról annyi elem tűnik el, hogy a tartalom kiférjen.
     * (A „Ma" melléksávja, az oldalsáv „Következő" listája.)
     */
    function levag(tarolo, valaszto) {
        if (!tarolo) {
            return;
        }

        var elemek = Array.prototype.slice.call(tarolo.querySelectorAll(valaszto));

        elemek.forEach(function (x) { x.hidden = false; });

        while (elemek.length > 1 && tarolo.scrollHeight > tarolo.clientHeight + 1) {
            elemek.pop().hidden = true;
        }
    }

    /* ---- Negyedév és év: kis hónapok ---- */

    function kisHonap(ev, ho, lista, reszletes) {
        var elso = hetfo(new Date(ev, ho, 1));
        var m = most();
        var html = '<div class="sdh-np-kh"><button type="button" class="sdh-np-kh__cim" data-np-nap="' + ev + '-' + p2(ho + 1) + '-01" data-np-hora>' + HONAPOK[ho] + '</button><div class="sdh-np-kh__racs">';

        [1, 2, 3, 4, 5, 6, 0].forEach(function (n) { html += '<span class="sdh-np-kh__nap">' + NAPOK_R[n] + '</span>'; });

        for (var i = 0; i < 42; i++) {
            var d = plusz(elso, i);

            if (d.getMonth() !== ho) {
                html += '<span class="sdh-np-kh__d is-mas"></span>';
                continue;
            }

            var holnap = plusz(d, 1);
            var napi = lista.filter(function (x) { return x.K < holnap && x.V >= d && (x.V > d || x.K >= d); });
            var kritikus = napi.some(function (x) { return x.pri === 'kritikus'; });
            var szint = Math.min(4, napi.length);
            var pottyok = '';

            if (reszletes) {
                napi.slice(0, 3).forEach(function (x) { pottyok += '<i class="sdh-np-potty sdh-np-szin--' + e(x.szin) + '"></i>'; });
            }

            html += '<button type="button" class="sdh-np-kh__d is-szint-' + szint + (ugyanNap(d, m) ? ' is-ma' : '') + (kritikus ? ' is-kritikus' : '') + '" data-np-nap="' + iso(d) + '" data-np-napra' +
                ' title="' + e(napSzoveg(d) + (napi.length ? '\n' + napi.slice(0, 6).map(function (x) { return (x.en ? '' : ido(x.K) + ' ') + x.cim; }).join('\n') + (napi.length > 6 ? '\n+' + (napi.length - 6) : '') : '')) + '">' +
                '<b>' + d.getDate() + '</b>' + (reszletes ? '<span class="sdh-np-kh__pottyok">' + pottyok + (napi.length > 3 ? '<em>+' + (napi.length - 3) + '</em>' : '') + '</span>' : '') + '</button>';
        }

        return html + '</div></div>';
    }

    function nezetNegyedev() {
        var q = Math.floor(A.datum.getMonth() / 3) * 3;
        var lista = esemenyek();
        var html = '<div class="sdh-np-ne">';

        for (var i = 0; i < 3; i++) {
            html += kisHonap(A.datum.getFullYear(), q + i, lista, true);
        }

        return html + '</div>';
    }

    function nezetEv() {
        var lista = esemenyek();
        var html = '<div class="sdh-np-ev">';

        for (var i = 0; i < 12; i++) {
            html += kisHonap(A.datum.getFullYear(), i, lista, false);
        }

        return html + '</div><div class="sdh-np-jelmagy"><span>Kevés</span><i class="is-szint-1"></i><i class="is-szint-2"></i><i class="is-szint-3"></i><i class="is-szint-4"></i><span>Sok esemény</span><i class="is-kritikus"></i><span>Nagyon fontos</span></div>';
    }

    /* ---- Listás nézetek: lapozás görgetés helyett ---- */

    function lapozo(osszes, laponkent) {
        var lapok = Math.max(1, Math.ceil(osszes / laponkent));

        A.lap = Math.max(0, Math.min(A.lap, lapok - 1));

        if (lapok < 2) {
            return '';
        }

        return '<div class="sdh-np-lapozo"><button type="button" data-np-lap="-1"' + (A.lap === 0 ? ' disabled' : '') + ' aria-label="Előző lap">' + IKON.bal + '</button>' +
            '<span>' + (A.lap + 1) + ' / ' + lapok + '</span>' +
            '<button type="button" data-np-lap="1"' + (A.lap >= lapok - 1 ? ' disabled' : '') + ' aria-label="Következő lap">' + IKON.jobb + '</button></div>';
    }

    function sorHtml(ev, napot) {
        var sz = szemely(ev.sz);
        var kat = A.adat.kategoriak[ev.kat];

        return '<div class="sdh-np-sor ' + osztalyok(ev) + '">' +
            '<button type="button" class="sdh-np-sor__kesz" data-np-kesz="' + ev.id + '" data-all="' + e(ev.all) + '"' + (ev.szerk ? '' : ' disabled') + ' title="' + (ev.all === 'kesz' ? 'Kész – visszaállítás tervezettre' : 'Megjelölés késznek') + '" aria-label="Megjelölés késznek">' + (ev.all === 'kesz' ? IKON.pipa : '') + '</button>' +
            '<span class="sdh-np-sor__ido">' + (napot ? '<em>' + HONAPOK_R[ev.K.getMonth()] + ' ' + ev.K.getDate() + '.</em>' : '') + (ev.en ? 'egész nap' : ido(ev.K) + '–' + ido(ev.V)) + '</span>' +
            '<button type="button" class="sdh-np-sor__fo"' + attr(ev) + '><strong>' + e(ev.cim) + jelek(ev) + '</strong>' +
            '<span>' + [ev.hely, kat ? kat.nev : '', ev.ugyfel, ev.ml ? 'Munkalap ' + ev.ml : ''].filter(Boolean).map(e).join(' · ') + '</span></button>' +
            (sz ? '<span class="sdh-np-sor__szemely"><i class="sdh-np-avatar sdh-np-avatar--kicsi sdh-np-szin--' + e(sz.szin) + '">' + e(sz.betu) + '</i>' + e(sz.nev) + '</span>' : '') +
            visszaHtml(ev.k, ev.v, 'sdh-np-sor__vissza') + '</div>';
    }

    function nezetNapirend() {
        var lista = esemenyek();
        var ma = napEleje(most());
        // Soronként kb. 54 képpont; a napok fejléce is egy sor.
        var fer = Math.max(4, Math.floor((nezetHely.clientHeight - 60 * nagyitas()) / (54 * nagyitas())));
        var sorok = [];
        var elozo = '';

        lista.forEach(function (ev) {
            var nap = iso(ev.K < idoszak().tol ? idoszak().tol : ev.K);

            if (nap !== elozo) {
                sorok.push({ fej: datum(nap) });
                elozo = nap;
            }

            sorok.push({ ev: ev });
        });

        if (!sorok.length) {
            return '<div class="sdh-np-ures sdh-np-ures--nagy">Ebben a 30 napban nincs esemény.<br><button type="button" class="sdh-gomb sdh-gomb--elsodleges" data-sdh-np-uj>Új esemény</button></div>';
        }

        var lap = lapozo(sorok.length, fer);
        var html = '<div class="sdh-np-lista">';

        sorok.slice(A.lap * fer, (A.lap + 1) * fer).forEach(function (s) {
            html += s.fej
                ? '<div class="sdh-np-lista__nap' + (ugyanNap(s.fej, ma) ? ' is-ma' : '') + '"><b>' + s.fej.getDate() + '</b><span>' + HONAPOK[s.fej.getMonth()] + ', ' + NAPOK[s.fej.getDay()] + (ugyanNap(s.fej, ma) ? ' – ma' : '') + '</span></div>'
                : sorHtml(s.ev, false);
        });

        return html + '</div>' + lap;
    }

    /* ---- Ma: a nap idővonala, lemaradt események, fontos levelek ---- */

    function nezetMa() {
        var m = most();
        var ma = napEleje(m);
        var holnap = plusz(ma, 1);
        var lista = esemenyek();
        var mai = lista.filter(function (ev) { return ev.K < holnap && ev.V >= ma && (ev.V > ma || ev.K >= ma); });
        var holnapi = lista.filter(function (ev) { return ev.K >= holnap; });
        var o = orak();

        // Szabad idő: a látható órasáv hátralévő részéből a még hátralévő (nem egész napos) események ideje.
        var napVege = new Date(ma.getTime() + (C.oraIg || 19) * 3600000);
        var hatra = Math.max(0, napVege - m);
        var foglalt = 0;

        mai.forEach(function (ev) {
            if (ev.sav || ev.all === 'kesz' || ev.all === 'elmaradt') {
                return;
            }

            foglalt += Math.max(0, Math.min(ev.V, napVege) - Math.max(ev.K, m));
        });

        var szabad = Math.max(0, hatra - foglalt);
        var fer = Math.max(3, Math.floor((nezetHely.clientHeight - 150 * nagyitas()) / (62 * nagyitas())));
        var lap = lapozo(mai.length, fer);
        var mostKesz = false;

        var html = '<div class="sdh-np-ma"><section class="sdh-np-ma__fo"><header class="sdh-np-ma__fej"><span class="sdh-np-ma__jel">' + IKON.naptar + '</span><div><h3>Ma</h3>' +
            '<p>' + (hatra > 0 ? e(tartam(szabad, false)) + ' szabad idő maradt ' + p2(C.oraIg || 19) + ':00-ig' : 'A mai nap órasávja (' + p2(o.tol) + '–' + p2(C.oraIg || 19) + ') véget ért') + ' · ' + mai.length + ' esemény</p></div>' +
            '<button type="button" class="sdh-gomb sdh-gomb--pill sdh-gomb--elsodleges" data-sdh-np-uj>' + IKON.plusz + '<span class="sdh-gomb__felirat">Új esemény</span></button></header><div class="sdh-np-ma__vonal">';

        function mostSor() {
            mostKesz = true;

            return '<div class="sdh-np-ma__most"><b>Most ' + ido(m) + '</b><i></i></div>';
        }

        mai.slice(A.lap * fer, (A.lap + 1) * fer).forEach(function (ev) {
            if (!mostKesz && !ev.sav && ev.K > m) {
                html += mostSor();
            }

            html += '<div class="sdh-np-ma__sor' + (ev.V < m && !ev.sav ? ' is-mult' : '') + (ev.K <= m && ev.V > m && !ev.sav ? ' is-most' : '') + '">' +
                '<span class="sdh-np-ma__ido">' + (ev.en ? 'egész nap' : ido(ev.K)) + '</span><i class="sdh-np-ma__pont"></i>' + sorHtml(ev, false) + '</div>';
        });

        if (!mostKesz && A.lap === 0) {
            html += mostSor();
        }

        if (!mai.length) {
            html += '<p class="sdh-np-ures">Mára nincs esemény a kijelölt naptárakban.</p>';
        }

        html += '</div>' + lap + '</section><aside class="sdh-np-ma__mellek">';

        var lem = (A.adat.lemaradt || []).slice(0, 4);

        html += '<div class="sdh-np-ma__doboz"><h4>' + IKON.csillag + 'Még ' + (A.adat.lemaradt || []).length + ', ami lejárt és nincs kész</h4>';

        lem.forEach(function (ev) {
            html += '<button type="button" class="sdh-np-kartya ' + osztalyok(ev) + '"' + attr(ev) + '><strong>' + e(ev.cim) + '</strong>' +
                '<span>' + HONAPOK_R[ev.K.getMonth()] + ' ' + ev.K.getDate() + '.' + (ev.en ? '' : ' ' + ido(ev.K)) + (szemely(ev.sz) ? ' · ' + e(szemely(ev.sz).nev) : '') + '</span></button>';
        });

        if (!lem.length) {
            html += '<p class="sdh-np-ures">Nincs lemaradt esemény.</p>';
        }

        html += '</div>';

        var levelek = (A.adat.levelek || []).slice(0, 3);

        if (C.levelUrl) {
            html += '<div class="sdh-np-ma__doboz"><h4>' + IKON.level + 'Fontos levelek</h4>';

            levelek.forEach(function (l) {
                html += '<a class="sdh-np-kartya sdh-np-kartya--level is-' + e(l.szint) + '" href="' + e(l.url) + '"><b class="sdh-np-kartya__szint">' + (l.szint === 'azonnal' ? 'azonnal' : 'ma') + '</b>' +
                    '<strong>' + e(l.targy) + '</strong><span>' + e(l.felado) + (l.ok ? ' – ' + e(l.ok) : '') + '</span></a>';
            });

            if (!levelek.length) {
                html += '<p class="sdh-np-ures">Nincs elintézetlen fontos levél.</p>';
            }

            html += '</div>';
        }

        html += '<div class="sdh-np-ma__doboz"><h4>' + IKON.ora + 'Holnap</h4>';

        holnapi.slice(0, 4).forEach(function (ev) {
            html += '<button type="button" class="sdh-np-kartya ' + osztalyok(ev) + '"' + attr(ev) + '><strong>' + e(ev.cim) + '</strong><span>' + (ev.en ? 'egész nap' : ido(ev.K) + '–' + ido(ev.V)) + (szemely(ev.sz) ? ' · ' + e(szemely(ev.sz).nev) : '') + '</span></button>';
        });

        if (!holnapi.length) {
            html += '<p class="sdh-np-ures">Holnapra még nincs esemény.</p>';
        } else if (holnapi.length > 4) {
            html += '<button type="button" class="sdh-np-tobb" data-np-nap="' + iso(holnap) + '" data-np-napra>+' + (holnapi.length - 4) + ' további</button>';
        }

        return html + '</div></aside></div>';
    }

    /* ---- Idővonal: soronként egy név; vízszintesen egy nap órái vagy a hét napjai ---- */

    function nevCella(sz, db) {
        return '<span class="sdh-np-iv__nev"><i class="sdh-np-avatar sdh-np-szin--' + e(sz.szin) + '">' + e(sz.betu) + '</i><b>' + e(sz.nev) + '</b><em>' + db + ' esemény</em></span>';
    }

    /** Hét: név × nap rács, a cellákban az aznapi események (ami nem fér ki: „+N"). */
    function idovonalHet() {
        var r = idoszak();
        var lista = esemenyek();
        var m = most();
        var nevek = lathatoSzemelyek();
        var html = '<div class="sdh-np-iv sdh-np-iv--het"><div class="sdh-np-iv__fej"><span class="sdh-np-iv__nev"></span>';

        for (var i = 0; i < 7; i++) {
            var d = plusz(r.tol, i);

            html += '<button type="button" class="sdh-np-iv__nap' + (ugyanNap(d, m) ? ' is-ma' : '') + '" data-np-nap="' + iso(d) + '" data-np-ivnap><b>' + NAPOK[d.getDay()] + '</b><span>' + HONAPOK_R[d.getMonth()] + ' ' + d.getDate() + '.</span></button>';
        }

        html += '</div><div class="sdh-np-iv__sorok">';

        nevek.forEach(function (sz) {
            var sajat = lista.filter(function (ev) { return ev.sz === sz.id || ev.resz.indexOf(sz.id) >= 0; });

            html += '<div class="sdh-np-iv__sor">' + nevCella(sz, sajat.length);

            for (var n = 0; n < 7; n++) {
                var nap = plusz(r.tol, n);
                var holnap = plusz(nap, 1);
                var napi = sajat.filter(function (ev) { return ev.K < holnap && ev.V >= nap && (ev.V > nap || ev.K >= nap); });

                html += '<div class="sdh-np-iv__cella' + (ugyanNap(nap, m) ? ' is-ma' : '') + '" data-np-cella="' + iso(nap) + '" data-np-szemely="' + sz.id + '">' +
                    '<div class="sdh-np-iv__lista" data-np-lista>' + napi.map(function (ev) { return csik(ev, true); }).join('') +
                    '<button type="button" class="sdh-np-tobb" data-np-nap="' + iso(nap) + '" data-np-napra hidden></button></div></div>';
            }

            html += '</div>';
        });

        if (!nevek.length) {
            html += '<p class="sdh-np-ures sdh-np-ures--nagy">Nincs kijelölt naptár.</p>';
        }

        return html + '</div></div>';
    }

    /** Nap: vízszintesen az órák, az események sávokban – ki mikor foglalt, mikor szabad. */
    function idovonalNap() {
        var nap = napEleje(A.datum);
        var holnap = plusz(nap, 1);
        var o = orak();
        var ossz = (o.ig - o.tol) * 60;
        var lista = esemenyek();
        var m = most();
        var nevek = lathatoSzemelyek();
        var html = '<div class="sdh-np-iv sdh-np-iv--nap"><div class="sdh-np-iv__fej"><span class="sdh-np-iv__nev"></span><div class="sdh-np-iv__orak">';

        for (var h = o.tol; h < o.ig; h++) {
            html += '<span style="left:' + ((h - o.tol) * 60 / ossz * 100) + '%">' + p2(h) + ':00</span>';
        }

        html += '</div></div><div class="sdh-np-iv__sorok">';

        nevek.forEach(function (sz) {
            var sajat = lista.filter(function (ev) {
                return (ev.sz === sz.id || ev.resz.indexOf(sz.id) >= 0) && ev.K < holnap && ev.V >= nap && (ev.V > nap || ev.K >= nap);
            }).map(function (ev) {
                var tol = ev.sav ? 0 : Math.max(0, Math.min(ossz, (ev.K - nap) / PERC - o.tol * 60));
                var ig = ev.sav ? ossz : Math.max(0, Math.min(ossz, (ev.V - nap) / PERC - o.tol * 60));

                // Rövid eseménynek is jut annyi szélesség, hogy az ideje és a neve eleje olvasható legyen;
                // a látható órasávon kívüli esemény a sáv szélén áll.
                var keskeny = Math.max(45, ossz * 0.085);
                var kezd = Math.min(tol, ossz - keskeny);

                return { ev: ev, tolP: kezd, igP: Math.max(ig, kezd + keskeny) };
            });

            hasabok(sajat);

            var savok = sajat.reduce(function (max, x) { return Math.max(max, x.hasabDb || 1); }, 1);

            html += '<div class="sdh-np-iv__sor">' + nevCella(sz, sajat.length) +
                '<div class="sdh-np-iv__palya" data-np-cella="' + iso(nap) + '" data-np-szemely="' + sz.id + '" data-np-vizszintes>';

            for (var hh = o.tol; hh < o.ig; hh++) {
                html += '<i class="sdh-np-iv__vonal" style="left:' + ((hh - o.tol) * 60 / ossz * 100) + '%"></i>';
            }

            sajat.forEach(function (x) {
                var magas = 100 / savok;

                html += '<button type="button" class="sdh-np-iv__ev ' + osztalyok(x.ev) + '"' + attr(x.ev) +
                    ' style="left:' + (x.tolP / ossz * 100) + '%;width:' + ((x.igP - x.tolP) / ossz * 100) + '%;top:calc(' + (x.hasab * magas) + '% + 3px);height:calc(' + magas + '% - 6px)">' +
                    '<span>' + (x.ev.en ? 'egész nap · ' : ido(x.ev.K) + ' ') + e(x.ev.cim) + '</span>' + jelek(x.ev) + '</button>';
            });

            if (ugyanNap(nap, m)) {
                var perc = m.getHours() * 60 + m.getMinutes() - o.tol * 60;

                if (perc >= 0 && perc <= ossz) {
                    html += '<i class="sdh-np-iv__most" style="left:' + (perc / ossz * 100) + '%"></i>';
                }
            }

            html += '</div></div>';
        });

        if (!nevek.length) {
            html += '<p class="sdh-np-ures sdh-np-ures--nagy">Nincs kijelölt naptár.</p>';
        }

        return html + '</div></div>';
    }

    function nezetIdovonal() {
        return A.ivMod === 'nap' ? idovonalNap() : idovonalHet();
    }

    /* ---- Tábla: a hét eseményei állapot szerint ---- */

    function nezetTabla() {
        var lista = esemenyek();
        var fer = Math.max(2, Math.floor((nezetHely.clientHeight - 70 * nagyitas()) / (74 * nagyitas())));
        var html = '<div class="sdh-np-tb">';

        Object.keys(C.allapotok || {}).forEach(function (all) {
            var sajat = lista.filter(function (ev) { return ev.all === all; });

            html += '<section class="sdh-np-tb__oszlop is-' + e(all) + '" data-np-allapot="' + e(all) + '"><header><b>' + e(C.allapotok[all]) + '</b><span>' + sajat.length + '</span></header><div class="sdh-np-tb__lista">';

            sajat.slice(0, sajat.length > fer ? fer - 1 : fer).forEach(function (ev) {
                var sz = szemely(ev.sz);

                html += '<button type="button" class="sdh-np-kartya sdh-np-kartya--tabla ' + osztalyok(ev) + '"' + attr(ev) + (ev.szerk ? ' draggable="true"' : '') + '>' +
                    '<strong>' + e(ev.cim) + jelek(ev) + '</strong>' +
                    '<span>' + NAPOK_R[ev.K.getDay()] + ' ' + ev.K.getDate() + '.' + (ev.en ? '' : ' ' + ido(ev.K)) + (sz ? ' · ' + e(sz.nev) : '') + '</span>' +
                    visszaHtml(ev.k, ev.v) + '</button>';
            });

            if (sajat.length > fer) {
                html += '<button type="button" class="sdh-np-tobb" data-np-nezet="napirend">+' + (sajat.length - fer + 1) + ' további – napirend</button>';
            }

            if (!sajat.length) {
                html += '<p class="sdh-np-ures">Húzz ide eseményt.</p>';
            }

            html += '</div></section>';
        });

        return html + '</div>';
    }

    /* ---- Rajzolás ---- */

    function rajzol() {
        if (!A.adat) {
            return;
        }

        magassag();
        savRajzol();
        oldalsavRajzol();

        var d = napEleje(A.datum);
        var html;

        switch (A.nezet) {
            case 'ma': html = nezetMa(); break;
            case 'nap': html = nezetNapok(1, d); break;
            case 'haromnap': html = nezetNapok(3, d); break;
            case 'munkahet': html = nezetNapok(5, hetfo(d)); break;
            case 'het': html = nezetNapok(7, hetfo(d)); break;
            case 'honap': html = nezetHonap(); break;
            case 'negyedev': html = nezetNegyedev(); break;
            case 'ev': html = nezetEv(); break;
            case 'idovonal': html = nezetIdovonal(); break;
            case 'szemelyek': html = nezetSzemelyek(); break;
            case 'tabla': html = nezetTabla(); break;
            default: html = nezetNapirend();
        }

        nezetHely.setAttribute('data-nezet', A.nezet);
        nezetHely.innerHTML = html;

        // Ahol cellákban állnak az események (hónap, idővonal – hét), csak annyi látszik, amennyi kifér.
        honapIgazit();
        levag(nezetHely.querySelector('.sdh-np-ma__mellek'), '.sdh-np-kartya, .sdh-np-tobb, .sdh-np-ures');

        mostVonal();
        visszaFrissit(nezetHely);
    }

    function nezetValt(nezet) {
        if (!C.nezetek || !C.nezetek[nezet]) {
            return;
        }

        A.nezet = nezet;
        A.lap = 0;
        tarol('nezet', nezet);
        tolt();
    }

    oldal = {
        tolt: tolt,
        ugras: function (d) {
            if (!isNaN(d.getTime())) {
                A.datum = napEleje(d);
                A.mini = new Date(d.getFullYear(), d.getMonth(), 1);

                if (A.nezet === 'ma' && !ugyanNap(d, most())) {
                    A.nezet = 'nap';
                }

                tolt();
            }
        },
        jelzesDb: function () { /* a menü jelvényét a jelzések rajzolója frissíti */ }
    };

    /* ---- Kattintások ---- */

    gyoker.addEventListener('click', function (ev) {
        var cel = ev.target;

        if (!cel.closest) {
            return;
        }

        var x;

        if (cel.closest('[data-sdh-np-uj]')) {
            ev.preventDefault();
            esemenyNyit(0, '', { datum: iso(A.nezet === 'ma' ? most() : A.datum) });

            return;
        }

        if ((x = cel.closest('[data-np-lep]'))) {
            lep(parseInt(x.getAttribute('data-np-lep'), 10));
            tolt();

            return;
        }

        if (cel.closest('[data-np-ma]')) {
            A.datum = napEleje(most());
            A.mini = new Date(A.datum.getFullYear(), A.datum.getMonth(), 1);
            A.lap = 0;
            tolt();

            return;
        }

        if ((x = cel.closest('[data-np-nezet]'))) {
            nezetValt(x.getAttribute('data-np-nezet'));

            return;
        }

        if (cel.closest('[data-np-teljes]')) {
            A.teljes = !A.teljes;
            tarol('teljes', A.teljes ? '1' : '0');
            rajzol();

            return;
        }

        if ((x = cel.closest('[data-np-ivmod]'))) {
            A.ivMod = x.getAttribute('data-np-ivmod') === 'nap' ? 'nap' : 'het';
            tarol('ivmod', A.ivMod);
            tolt();

            return;
        }

        if ((x = cel.closest('[data-np-mini]'))) {
            A.mini = pluszHo(A.mini, parseInt(x.getAttribute('data-np-mini'), 10));
            oldalsavRajzol();

            return;
        }

        if ((x = cel.closest('[data-np-lap]'))) {
            A.lap += parseInt(x.getAttribute('data-np-lap'), 10);
            rajzol();

            return;
        }

        if ((x = cel.closest('[data-np-szemely]')) && !x.hasAttribute('data-np-cella')) {
            window.SDH_MUHELY_APP.nyit('naptarszemely', x.getAttribute('data-np-szemely'));

            return;
        }

        if ((x = cel.closest('[data-np-szur]'))) {
            var ertek = x.getAttribute('data-np-szur');
            var mind = ((A.adat && A.adat.szemelyek) || []).map(function (s) { return s.id; });

            if (ertek === 'mind') {
                A.szurt = null;
            } else if (x.hasAttribute('data-np-csak')) {
                A.szurt = [parseInt(ertek, 10)];
            } else {
                var id = parseInt(ertek, 10);
                var most2 = A.szurt ? A.szurt.slice() : mind.slice();
                var hol = most2.indexOf(id);

                if (hol >= 0) {
                    most2.splice(hol, 1);
                } else {
                    most2.push(id);
                }

                // Senki kijelölve = mindenki; mindenki kijelölve = nincs szűrés.
                A.szurt = most2.length === 0 || most2.length === mind.length ? null : most2;
            }

            tarol('szurt', JSON.stringify(A.szurt));
            tolt();

            return;
        }

        if ((x = cel.closest('[data-np-kesz]'))) {
            ev.preventDefault();
            x.disabled = true;

            ker('naptar_allapot', { id: x.getAttribute('data-np-kesz'), allapot: x.getAttribute('data-all') === 'kesz' ? 'tervezett' : 'kesz' })
                .then(tolt)
                .catch(function () { x.disabled = false; });

            return;
        }

        if ((x = cel.closest('[data-np-ev]'))) {
            if (huzas.volt) {
                return;
            }

            ev.preventDefault();
            esemenyNyit(x.getAttribute('data-np-ev'), x.getAttribute('data-np-alk'));

            return;
        }

        if ((x = cel.closest('[data-np-nap]'))) {
            var nap = datum(x.getAttribute('data-np-nap'));

            if (x.hasAttribute('data-np-ivnap')) {
                A.ivMod = 'nap';
                tarol('ivmod', 'nap');
            } else if (x.hasAttribute('data-np-hetre')) {
                A.nezet = 'het';
            } else if (x.hasAttribute('data-np-hora')) {
                A.nezet = 'honap';
            } else if (x.hasAttribute('data-np-napra')) {
                A.nezet = 'nap';
            } else if (A.nezet === 'ma') {
                A.nezet = 'nap';
            }

            tarol('nezet', A.nezet);
            A.lap = 0;
            oldal.ugras(nap);

            return;
        }

        // Üres hely a rácsban: új esemény arra a napra / időpontra / névre.
        if ((x = cel.closest('[data-np-cella]')) && !huzas.volt) {
            var p = { datum: x.getAttribute('data-np-cella') };

            if (x.hasAttribute('data-np-szemely')) {
                p.szemely = x.getAttribute('data-np-szemely');
            }

            if (x.hasAttribute('data-np-egesz')) {
                if (A.nezet !== 'honap') {
                    p.egesz = '1';
                }
            } else if (x.classList.contains('sdh-np-ir__oszlop') || x.hasAttribute('data-np-vizszintes')) {
                var o = orak();
                var t = x.getBoundingClientRect();
                var arany = x.hasAttribute('data-np-vizszintes') ? (ev.clientX - t.left) / t.width : (ev.clientY - t.top) / t.height;
                var perc = o.tol * 60 + arany * (o.ig - o.tol) * 60;

                perc = Math.max(0, Math.min(1410, Math.round(perc / 30) * 30));
                p.ido = p2(Math.floor(perc / 60)) + ':' + p2(perc % 60);
            }

            esemenyNyit(0, '', p);
        }
    });

    var keresoIdo = 0;

    gyoker.addEventListener('input', function (ev) {
        if (ev.target.hasAttribute && ev.target.hasAttribute('data-np-kereso')) {
            A.kereses = ev.target.value.trim();
            A.lap = 0;
            window.clearTimeout(keresoIdo);
            keresoIdo = window.setTimeout(rajzol, 180);
        }
    });

    gyoker.addEventListener('keydown', function (ev) {
        var blokk = ev.target.closest ? ev.target.closest('.sdh-np-blokkev') : null;

        if (blokk && (ev.key === 'Enter' || ev.key === ' ')) {
            ev.preventDefault();
            esemenyNyit(blokk.getAttribute('data-np-ev'), blokk.getAttribute('data-np-alk'));
        }
    });

    /* ---- Húzás az időrácsban: áthelyezés és átméretezés (15 perces lépés) ---- */

    var huzas = { volt: false };

    function esemenyKeres(id, alkalom) {
        var lista = (A.adat && A.adat.esemenyek) || [];

        for (var i = 0; i < lista.length; i++) {
            if (String(lista[i].id) === String(id) && lista[i].k.slice(0, 10) === alkalom) {
                return lista[i];
            }
        }

        return null;
    }

    function athelyez(ev, kezdet, veg, szemelyId) {
        var adat = { id: ev.id, kezdet: isoIdo(kezdet), veg: isoIdo(veg) };

        if (szemelyId) {
            adat.szemely = szemelyId;
        }

        return ker('naptar_athelyez', adat).then(tolt).catch(function (hiba) {
            tolt();

            if (window.SDH_AI_API && typeof window.SDH_AI_API.buborek === 'function') {
                window.SDH_AI_API.buborek(hiba.message, [], { ms: 5000 });
            }
        });
    }

    nezetHely.addEventListener('pointerdown', function (pe) {
        var blokk = pe.target.closest ? pe.target.closest('.sdh-np-blokkev[data-np-huz]') : null;

        if (!blokk || pe.button !== 0) {
            return;
        }

        var ev = esemenyKeres(blokk.getAttribute('data-np-ev'), blokk.getAttribute('data-np-alk'));
        var oszlop = blokk.closest('.sdh-np-ir__oszlop');

        // Csak az egy napra eső blokk húzható (az éjfélen átnyúló eseményt az űrlapján lehet módosítani).
        if (!ev || !oszlop || !ugyanNap(ev.K, new Date(ev.V.getTime() - 1))) {
            return;
        }

        var meretez = !!pe.target.closest('[data-np-meretez]');
        var o = orak();
        var ossz = (o.ig - o.tol) * 60;
        var kezdoY = pe.clientY;
        var kezdoX = pe.clientX;
        var percPx = oszlop.getBoundingClientRect().height / ossz;
        var hossz = (ev.V - ev.K) / PERC;
        var kezdoPerc = ev.K.getHours() * 60 + ev.K.getMinutes();
        var ujPerc = kezdoPerc;
        var ujHossz = hossz;
        var celOszlop = oszlop;
        var mozdult = false;

        function mozog(me) {
            var dy = me.clientY - kezdoY;

            if (!mozdult && Math.abs(dy) < 5 && Math.abs(me.clientX - kezdoX) < 5) {
                return;
            }

            mozdult = true;
            blokk.classList.add('is-huzott');

            var lepes = Math.round(dy / percPx / 15) * 15;

            if (meretez) {
                ujHossz = Math.max(15, Math.min(1440 - kezdoPerc, hossz + lepes));
                blokk.style.height = (ujHossz / ossz * 100) + '%';
            } else {
                ujPerc = Math.max(0, Math.min(1440 - hossz, kezdoPerc + lepes));
                blokk.style.top = ((ujPerc - o.tol * 60) / ossz * 100) + '%';

                var alatta = document.elementFromPoint(me.clientX, me.clientY);
                var uj = alatta && alatta.closest ? alatta.closest('.sdh-np-ir__oszlop') : null;

                if (uj && uj !== celOszlop) {
                    celOszlop = uj;
                    celOszlop.appendChild(blokk);
                    blokk.style.left = '2px';
                    blokk.style.width = 'calc(100% - 4px)';
                }
            }

            var k = new Date(datum(celOszlop.getAttribute('data-np-cella')).getTime() + ujPerc * PERC);
            var idoElem = blokk.querySelector('.sdh-np-blokkev__ido');

            if (idoElem) {
                idoElem.textContent = ido(k) + '–' + ido(new Date(k.getTime() + ujHossz * PERC));
            }
        }

        function vege() {
            document.removeEventListener('pointermove', mozog);
            document.removeEventListener('pointerup', vege);
            document.removeEventListener('pointercancel', vege);

            if (!mozdult) {
                return;
            }

            // A húzást lezáró kattintás ne nyissa meg az eseményt.
            huzas.volt = true;
            window.setTimeout(function () { huzas.volt = false; }, 250);

            var k = new Date(datum(celOszlop.getAttribute('data-np-cella')).getTime() + ujPerc * PERC);

            athelyez(ev, k, new Date(k.getTime() + ujHossz * PERC), celOszlop !== oszlop ? celOszlop.getAttribute('data-np-szemely') : '');
        }

        document.addEventListener('pointermove', mozog);
        document.addEventListener('pointerup', vege);
        document.addEventListener('pointercancel', vege);
    });

    /* ---- Húzás a hónapban (másik napra) és a táblán (másik állapotba) ---- */

    var huzott = null;

    nezetHely.addEventListener('dragstart', function (de) {
        var elem = de.target.closest ? de.target.closest('[draggable="true"][data-np-ev]') : null;

        if (!elem) {
            return;
        }

        huzott = esemenyKeres(elem.getAttribute('data-np-ev'), elem.getAttribute('data-np-alk'));

        if (huzott && de.dataTransfer) {
            de.dataTransfer.effectAllowed = 'move';
            de.dataTransfer.setData('text/plain', String(huzott.id));
        }
    });

    function ejtoHely(cel) {
        if (!cel.closest) {
            return null;
        }

        return A.nezet === 'tabla' ? cel.closest('[data-np-allapot]') : cel.closest('.sdh-np-ho__cella, .sdh-np-ir__savcella');
    }

    nezetHely.addEventListener('dragover', function (de) {
        var hely = huzott ? ejtoHely(de.target) : null;

        if (hely) {
            de.preventDefault();
            hely.classList.add('is-ejto');
        }
    });

    nezetHely.addEventListener('dragleave', function (de) {
        var hely = ejtoHely(de.target);

        if (hely && !hely.contains(de.relatedTarget)) {
            hely.classList.remove('is-ejto');
        }
    });

    nezetHely.addEventListener('drop', function (de) {
        var hely = huzott ? ejtoHely(de.target) : null;
        var ev = huzott;

        huzott = null;

        if (!hely || !ev) {
            return;
        }

        de.preventDefault();
        hely.classList.remove('is-ejto');

        if (A.nezet === 'tabla') {
            if (hely.getAttribute('data-np-allapot') !== ev.all) {
                ker('naptar_allapot', { id: ev.id, allapot: hely.getAttribute('data-np-allapot') }).then(tolt).catch(tolt);
            }

            return;
        }

        var ujNap = datum(hely.getAttribute('data-np-cella'));
        var eltolas = Math.round((ujNap - napEleje(ev.K)) / NAP_MS);

        if (eltolas !== 0) {
            athelyez(ev, plusz(ev.K, eltolas), plusz(ev.V, eltolas), '');
        }
    });

    nezetHely.addEventListener('dragend', function () {
        huzott = null;

        Array.prototype.forEach.call(nezetHely.querySelectorAll('.is-ejto'), function (x) { x.classList.remove('is-ejto'); });
    });

    /* ---- Indulás ---- */

    var meretIdo = 0;

    window.addEventListener('resize', function () {
        window.clearTimeout(meretIdo);
        meretIdo = window.setTimeout(rajzol, 120);
    });

    // Percenként frissül (a „Ma" szabad ideje, a mini naptár mai napja); a nyitott popupot nem zavarja.
    window.setInterval(function () {
        if (document.visibilityState === 'visible' && !document.querySelector('dialog[open]')) {
            tolt();
        }
    }, 120000);

    tolt().then(function () {
        if (C.esemeny) {
            esemenyNyit(C.esemeny, C.datum || '');
        }
    });
}());
