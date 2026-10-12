/**
 * SDH Műhely – Beállítások (0.47).
 *
 * A beállítások egyetlen oldalon vannak, témánként külön lapfülön; minden
 * lapfül külön ment. Ez a fájl mindkét felületen (műhely és wp-admin) fut:
 *
 *  - Mentés helyben: hiba esetén az üzenet a gomb mellett jelenik meg, siker
 *    után a lapfül frissül (a görgetés helye megmarad).
 *  - Nem mentett módosítás: lapfülváltás előtt rákérdez (Mentés / Elvetés).
 *  - Kép / videó feltöltése (logó, háttér) a médiatárba, kis előnézettel –
 *    a műhely-felületen nincs WordPress-médiaválasztó.
 *  - A színválasztó és a színkód-mező összekötése, a csúszkák kijelzője.
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};
    var gyoker = document.querySelector('[data-sdh-beallitasok]');

    if (!gyoker) {
        return;
    }

    var urlap = gyoker.querySelector('[data-sdh-beall-urlap]');
    var allapot = gyoker.querySelector('[data-sdh-beall-allapot]');
    var mentGomb = gyoker.querySelector('[data-sdh-beall-ment]');
    var piszkos = false;

    function ajaxCim() {
        return new URL(B.ajax || '/wp-admin/admin-ajax.php', window.location.origin).toString();
    }

    function nonce() {
        var mezo = gyoker.querySelector('input[name="sdh_nonce"]');

        return mezo ? mezo.value : '';
    }

    function jelez(szoveg, tipus) {
        if (!allapot) {
            return;
        }

        allapot.textContent = szoveg || '';
        allapot.className = 'sdh-beall__allapot' + (tipus ? ' is-' + tipus : '');
    }

    /* ---------------------------------------------------------------- */
    /* Görgetés helye a mentés utáni frissítéshez                        */
    /* ---------------------------------------------------------------- */

    var GORGETES = 'sdh-beall-gorgetes';

    try {
        var mentett = JSON.parse(sessionStorage.getItem(GORGETES) || 'null');

        sessionStorage.removeItem(GORGETES);

        if (mentett && mentett.ful === (gyoker.querySelector('[data-sdh-beall-ful]') || {}).getAttribute('data-sdh-beall-ful')) {
            window.scrollTo(0, mentett.y || 0);
        }
    } catch (e) { /* a görgetés helye nem létfontosságú */ }

    function ujratolt() {
        var cim = new URL(window.location.href);
        var ful = gyoker.querySelector('[data-sdh-beall-ful]').getAttribute('data-sdh-beall-ful');

        cim.searchParams.set('ful', ful);
        cim.searchParams.set('uzenet', 'mentve');

        try {
            sessionStorage.setItem(GORGETES, JSON.stringify({ ful: ful, y: window.scrollY }));
        } catch (e) { /* nem baj */ }

        window.location.href = cim.toString();
    }

    /* ---------------------------------------------------------------- */
    /* Mentés                                                            */
    /* ---------------------------------------------------------------- */

    function ment(utana) {
        if (!urlap) {
            return;
        }

        if (urlap.reportValidity && !urlap.reportValidity()) {
            return;
        }

        var adat = new FormData(urlap);

        adat.set('action', 'sdh_muhely_beallitasok_ment');

        if (mentGomb) {
            mentGomb.disabled = true;
        }

        jelez('Mentés…', '');

        fetch(ajaxCim(), { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (valasz) {
                return valasz.text().then(function (szoveg) {
                    try {
                        return JSON.parse(szoveg);
                    } catch (e) {
                        throw new Error(valasz.status === 403 || szoveg === '-1'
                            ? 'Lejárt a munkamenet – töltsd újra az oldalt, és próbáld újra.'
                            : 'A szerver válasza nem értelmezhető (' + valasz.status + ').');
                    }
                });
            })
            .then(function (json) {
                if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.uzenet) || 'A mentés nem sikerült.');
                }

                piszkos = false;
                jelez('Elmentve.', 'jo');

                if (typeof utana === 'function') {
                    utana();
                } else {
                    // A lapfül a mentett állapotot mutassa (pl. „a kulcs megadva", új logó, új színek).
                    ujratolt();
                }
            })
            .catch(function (hiba) {
                if (mentGomb) {
                    mentGomb.disabled = false;
                }

                jelez(hiba.message, 'hiba');
            });
    }

    if (urlap) {
        urlap.addEventListener('submit', function (esemeny) {
            esemeny.preventDefault();
            ment();
        });

        var megvaltozott = function (esemeny) {
            // A fájlválasztó és a feltöltés saját gombjai nem számítanak módosításnak.
            if (esemeny.target && esemeny.target.type === 'file') {
                return;
            }

            if (!piszkos) {
                piszkos = true;
                jelez('Nem mentett módosítás.', 'figyelem');
            }
        };

        urlap.addEventListener('input', megvaltozott);
        urlap.addEventListener('change', megvaltozott);

        // Ctrl+S / Cmd+S: mentés.
        document.addEventListener('keydown', function (esemeny) {
            if ((esemeny.ctrlKey || esemeny.metaKey) && (esemeny.key === 's' || esemeny.key === 'S')) {
                esemeny.preventDefault();
                ment();
            }
        });
    }

    /* ---------------------------------------------------------------- */
    /* Lapfülváltás nem mentett módosítással                             */
    /* ---------------------------------------------------------------- */

    gyoker.addEventListener('click', function (esemeny) {
        var pill = esemeny.target.closest ? esemeny.target.closest('.sdh-beall__menu a.sdh-pill') : null;

        if (!pill || !piszkos || !urlap) {
            return;
        }

        esemeny.preventDefault();

        var hova = pill.href;
        var regi = gyoker.querySelector('.sdh-beall__kerdes');

        if (regi) {
            regi.remove();
        }

        var sav = document.createElement('div');

        sav.className = 'sdh-beall__kerdes';
        sav.setAttribute('role', 'alertdialog');
        sav.innerHTML = '<span>Ezen a lapfülön nem mentett módosítás van.</span>';

        [['Mentés és tovább', 'sdh-gomb sdh-gomb--elsodleges', function () { ment(function () { window.location.href = hova; }); }],
         ['Elvetés és tovább', 'sdh-gomb sdh-gomb--vilagos', function () { window.location.href = hova; }],
         ['Maradok', 'sdh-gomb sdh-gomb--vilagos', function () { sav.remove(); }]].forEach(function (g) {
            var gomb = document.createElement('button');

            gomb.type = 'button';
            gomb.className = g[1];
            gomb.textContent = g[0];
            gomb.addEventListener('click', g[2]);
            sav.appendChild(gomb);
        });

        gyoker.querySelector('.sdh-beall__menu').insertAdjacentElement('afterend', sav);
        sav.querySelector('button').focus();
    });

    /* ---------------------------------------------------------------- */
    /* Kép / videó: feltöltés, törlés, előnézet                          */
    /* ---------------------------------------------------------------- */

    function elonezet(doboz) {
        var tipus = doboz.getAttribute('data-sdh-media');
        var mezo = doboz.querySelector('input[type="text"]');
        var elo = doboz.querySelector('[data-sdh-media-elo]');
        var torol = doboz.querySelector('[data-sdh-media-torol]');
        var cim = (mezo.value || '').trim();

        if (torol) {
            torol.hidden = cim === '';
        }

        if (!elo) {
            return;
        }

        elo.innerHTML = '';

        if (cim === '' || !/^(https?:)?\/\//i.test(cim)) {
            return;
        }

        var elem = document.createElement(tipus === 'video' ? 'video' : 'img');

        if (tipus === 'video') {
            elem.muted = true;
            elem.playsInline = true;
            elem.preload = 'metadata';
        } else {
            elem.alt = '';
        }

        elem.src = cim;
        elo.appendChild(elem);
    }

    function feltolt(doboz, gomb) {
        var tipus = doboz.getAttribute('data-sdh-media') === 'video' ? 'video' : 'image';
        var valaszto = document.createElement('input');

        valaszto.type = 'file';
        valaszto.accept = tipus === 'video' ? 'video/mp4,video/webm' : 'image/png,image/jpeg,image/gif,image/webp';

        valaszto.addEventListener('change', function () {
            if (!valaszto.files || !valaszto.files[0]) {
                return;
            }

            var adat = new FormData();
            var felirat = gomb.textContent;

            adat.set('action', 'sdh_muhely_beallitasok_feltolt');
            adat.set('sdh_nonce', nonce());
            adat.set('tipus', tipus);
            adat.set('fajl', valaszto.files[0]);

            gomb.disabled = true;
            gomb.textContent = 'Feltöltés…';

            fetch(ajaxCim(), { method: 'POST', body: adat, credentials: 'same-origin' })
                .then(function (valasz) {
                    return valasz.json().catch(function () {
                        throw new Error('A feltöltés nem sikerült (' + valasz.status + ') – lehet, hogy a fájl túl nagy.');
                    });
                })
                .then(function (json) {
                    if (!json || !json.success) {
                        throw new Error((json && json.data && json.data.uzenet) || 'A feltöltés nem sikerült.');
                    }

                    var mezo = doboz.querySelector('input[type="text"]');

                    mezo.value = json.data.url;
                    mezo.dispatchEvent(new Event('change', { bubbles: true }));
                })
                .catch(function (hiba) {
                    jelez(hiba.message, 'hiba');
                })
                .then(function () {
                    gomb.disabled = false;
                    gomb.textContent = felirat;
                });
        });

        valaszto.click();
    }

    gyoker.addEventListener('click', function (esemeny) {
        var cel = esemeny.target;
        var valaszt = cel.closest ? cel.closest('[data-sdh-media-valaszt]') : null;

        if (valaszt) {
            esemeny.preventDefault();
            feltolt(valaszt.closest('[data-sdh-media]'), valaszt);

            return;
        }

        var torol = cel.closest ? cel.closest('[data-sdh-media-torol]') : null;

        if (torol) {
            esemeny.preventDefault();

            var mezo = torol.closest('[data-sdh-media]').querySelector('input[type="text"]');

            mezo.value = '';
            mezo.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    gyoker.addEventListener('change', function (esemeny) {
        var doboz = esemeny.target.closest ? esemeny.target.closest('[data-sdh-media]') : null;

        if (doboz && esemeny.target.type === 'text') {
            elonezet(doboz);
        }
    });

    /* ---------------------------------------------------------------- */
    /* Színválasztó ↔ színkód, csúszka kijelzője                         */
    /* ---------------------------------------------------------------- */

    Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-szin-cel]'), function (valaszto) {
        var mezo = document.getElementById(valaszto.getAttribute('data-sdh-szin-cel'));

        if (!mezo) {
            return;
        }

        valaszto.addEventListener('input', function () {
            mezo.value = valaszto.value;
        });

        mezo.addEventListener('change', function () {
            var v = mezo.value.trim();

            if (/^[0-9a-f]{6}$/i.test(v)) {
                v = '#' + v;
            }

            if (/^#[0-9a-f]{3}$/i.test(v)) {
                v = '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
            }

            if (/^#[0-9a-f]{6}$/i.test(v)) {
                valaszto.value = v.toLowerCase();
            }
        });
    });

    // Beállítások › SMS: kapcsolat-teszt és teszt SMS (0.57).
    gyoker.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-sms-akcio]') : null;

        if (!gomb) {
            return;
        }

        var akcio = gomb.getAttribute('data-sdh-sms-akcio') || '';
        var hely = gomb.parentNode ? gomb.parentNode.querySelector('[data-sdh-sms-uzenet]') : null;
        var adat = new FormData();
        var szamMezo = document.getElementById(gomb.getAttribute('data-sdh-sms-szam') || '');

        adat.set('action', akcio);
        adat.set('_wpnonce', gomb.getAttribute('data-nonce') || '');

        if (szamMezo) {
            adat.set('szam', szamMezo.value);
        }

        gomb.disabled = true;

        if (hely) {
            hely.textContent = 'Egy pillanat…';
        }

        fetch(ajaxCim(), { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                if (hely) {
                    hely.textContent = (v && v.data && v.data.uzenet) || (v && v.success ? 'Kész.' : 'Nem sikerült.');
                    hely.style.color = v && v.success ? '' : 'var(--sdh-hiba, #be2f2f)';
                }
            })
            .catch(function () {
                if (hely) {
                    hely.textContent = 'Nem sikerült elérni a szervert.';
                }
            })
            .then(function () { gomb.disabled = false; });
    });

    // Beállítások › Mentés: „Mentés most" (a napi mentéstől függetlenül).
    gyoker.addEventListener('click', function (esemeny) {
        var gomb = esemeny.target.closest ? esemeny.target.closest('[data-sdh-mentes-most]') : null;

        if (!gomb) {
            return;
        }

        var hely = gyoker.querySelector('[data-sdh-mentes-uzenet]');
        var adat = new FormData();

        adat.set('action', 'sdh_muhely_mentes_most');
        adat.set('_wpnonce', gomb.getAttribute('data-nonce') || '');
        gomb.disabled = true;

        if (hely) {
            hely.textContent = 'Mentés készül…';
        }

        fetch(ajaxCim(), { method: 'POST', body: adat, credentials: 'same-origin' })
            .then(function (v) { return v.json(); })
            .then(function (v) {
                if (hely) {
                    hely.textContent = (v && v.data && v.data.uzenet) || (v && v.success ? 'Kész.' : 'A mentés nem sikerült.');
                }

                if (v && v.success) {
                    window.setTimeout(function () { window.location.reload(); }, 1400);
                }
            })
            .catch(function () {
                if (hely) {
                    hely.textContent = 'Nem sikerült elérni a szervert.';
                }
            })
            .then(function () { gomb.disabled = false; });
    });

    Array.prototype.forEach.call(gyoker.querySelectorAll('[data-sdh-kimenet]'), function (kimenet) {
        var mezo = document.getElementById(kimenet.getAttribute('data-sdh-kimenet'));

        if (mezo) {
            mezo.addEventListener('input', function () {
                kimenet.textContent = mezo.value;
            });
        }
    });
    /* ---------------------------------------------------------------- */
    /* Mockupok: új sor, törlés, generáló szöveg (0.56.0)               */
    /* ---------------------------------------------------------------- */

    var mockupLista = gyoker.querySelector('[data-sdh-mockup-lista]');

    if (mockupLista) {
        var mockupSablonMezo = gyoker.querySelector('[data-sdh-mockup-sablon]');
        var mockupSorSablon = gyoker.querySelector('[data-sdh-mockup-sablon-sor]');
        var mockupSzam = mockupLista.querySelectorAll('[data-sdh-mockup-sor]').length + 1000;

        var mockupPrompt = function (sor) {
            var leiras = sor.querySelector('[data-sdh-mockup-leiras]');
            var nev = sor.querySelector('input[name$="[nev]"]');
            var cel = sor.querySelector('[data-sdh-mockup-prompt]');
            var targy = (leiras && leiras.value.trim()) || (nev && nev.value.trim().toLowerCase()) || 'device';
            var sablon = (mockupSablonMezo && mockupSablonMezo.value.trim()) || '{eszkoz}';

            if (cel) {
                cel.value = sablon.split('{eszkoz}').join(targy);
            }
        };

        var mockupValtozott = function () {
            piszkos = true;
        };

        gyoker.addEventListener('click', function (esemeny) {
            var cel = esemeny.target;
            var uj = cel.closest ? cel.closest('[data-sdh-mockup-uj]') : null;
            var torol = cel.closest ? cel.closest('[data-sdh-mockup-torol]') : null;
            var masol = cel.closest ? cel.closest('[data-sdh-mockup-masol]') : null;

            if (uj && mockupSorSablon) {
                esemeny.preventDefault();
                mockupSzam += 1;

                var tarto = document.createElement('div');

                tarto.innerHTML = mockupSorSablon.innerHTML.split('__I__').join(String(mockupSzam));

                var sor = tarto.querySelector('[data-sdh-mockup-sor]');

                if (sor) {
                    mockupLista.appendChild(sor);
                    sor.querySelector('input[name$="[nev]"]').focus();
                    mockupValtozott();
                }

                return;
            }

            if (torol) {
                esemeny.preventDefault();

                // Kétlépcsős: az első kattintás megerősítést kér, a második töröl.
                if (!torol.classList.contains('is-biztos')) {
                    torol.classList.add('is-biztos');
                    torol.textContent = 'Biztosan?';
                    window.setTimeout(function () {
                        torol.classList.remove('is-biztos');
                        torol.innerHTML = '&times;';
                    }, 4000);

                    return;
                }

                torol.closest('[data-sdh-mockup-sor]').remove();
                mockupValtozott();
                jelez('A mockup lekerül a listáról, ha mented a lapfület.', 'info');

                return;
            }

            if (masol) {
                esemeny.preventDefault();

                var mezo = masol.closest('[data-sdh-mockup-sor]').querySelector('[data-sdh-mockup-prompt]');
                var kesz = function () {
                    masol.textContent = 'Kimásolva ✓';
                    window.setTimeout(function () { masol.textContent = 'Másolás'; }, 2000);
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(mezo.value).then(kesz, function () {
                        mezo.select();
                        document.execCommand('copy');
                        kesz();
                    });
                } else {
                    mezo.select();
                    document.execCommand('copy');
                    kesz();
                }
            }
        });

        gyoker.addEventListener('input', function (esemeny) {
            var mezo = esemeny.target;

            if (mezo === mockupSablonMezo) {
                Array.prototype.forEach.call(mockupLista.querySelectorAll('[data-sdh-mockup-sor]'), mockupPrompt);

                return;
            }

            var sor = mezo.closest ? mezo.closest('[data-sdh-mockup-sor]') : null;

            if (sor && (mezo.hasAttribute('data-sdh-mockup-leiras') || /\[nev\]$/.test(mezo.name || ''))) {
                mockupPrompt(sor);
            }
        });
    }
}());
