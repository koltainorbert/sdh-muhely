/* Beállítások › Mentés (0.73.0): külső mentési helyek (próba, lista, Google Drive összekapcsolás),
   visszaállítás előnézettel és kétlépcsős megerősítéssel, import fájlból. */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};

    function ajaxCim(gyoker) {
        return new URL(gyoker.getAttribute('data-ajax') || B.ajax || '/wp-admin/admin-ajax.php', window.location.origin).toString();
    }

    function kuld(gyoker, muvelet, mezok) {
        var adat = new FormData();

        adat.set('action', muvelet);
        adat.set('_wpnonce', gyoker.getAttribute('data-nonce') || '');

        Object.keys(mezok || {}).forEach(function (k) {
            adat.set(k, mezok[k]);
        });

        return fetch(ajaxCim(gyoker), {method: 'POST', body: adat, credentials: 'same-origin'})
            .then(function (v) { return v.json(); })
            .catch(function () { return {success: false, data: {uzenet: 'Nem sikerült elérni a szervert.'}}; });
    }

    function uzenet(v, alap) {
        return (v && v.data && v.data.uzenet) || alap;
    }

    function elem(cimke, osztaly, szoveg) {
        var e = document.createElement(cimke);

        if (osztaly) {
            e.className = osztaly;
        }

        if (szoveg !== undefined) {
            e.textContent = szoveg;
        }

        return e;
    }

    /* Kétlépcsős gomb: az első kattintás megerősítést kér, a második végrehajt. */
    function ketlepcsos(gomb, felirat, megerosites, vegrehajt) {
        var ido = null;

        gomb.textContent = felirat;
        gomb.addEventListener('click', function () {
            if (gomb.getAttribute('data-sdh-biztos') === '1') {
                window.clearTimeout(ido);
                gomb.removeAttribute('data-sdh-biztos');
                gomb.textContent = felirat;
                vegrehajt(gomb);

                return;
            }

            gomb.setAttribute('data-sdh-biztos', '1');
            gomb.textContent = megerosites;
            ido = window.setTimeout(function () {
                gomb.removeAttribute('data-sdh-biztos');
                gomb.textContent = felirat;
            }, 6000);
        });
    }

    function indit(gyoker) {
        var panel = gyoker.querySelector('[data-sdh-vissza-panel]');
        var tartalom = gyoker.querySelector('[data-sdh-vissza-tartalom]');

        /* ---- Feltételes mezők (pl. a NAS módjától függő címek) ---------------- */
        function feltetelek(kartya) {
            var lista = kartya.querySelectorAll('[data-sdh-ha-mezo]');

            for (var i = 0; i < lista.length; i++) {
                var el = lista[i];
                var forras = kartya.querySelector('[data-sdh-cel-mezo="' + el.getAttribute('data-sdh-ha-mezo') + '"]');
                var ertek = forras ? (forras.type === 'checkbox' ? (forras.checked ? '1' : '') : forras.value) : '';

                el.hidden = ertek !== el.getAttribute('data-sdh-ha-ertek');
            }
        }

        var kartyak = gyoker.querySelectorAll('[data-sdh-cel]');

        for (var k = 0; k < kartyak.length; k++) {
            feltetelek(kartyak[k]);
        }

        gyoker.addEventListener('change', function (e) {
            var kartya = e.target.closest ? e.target.closest('[data-sdh-cel]') : null;

            if (kartya) {
                feltetelek(kartya);
            }
        });

        function mezok(kartya) {
            var ki = {cel: kartya.getAttribute('data-sdh-cel')};
            var lista = kartya.querySelectorAll('[data-sdh-cel-mezo]');

            for (var i = 0; i < lista.length; i++) {
                var m = lista[i];

                ki['mezo[' + m.getAttribute('data-sdh-cel-mezo') + ']'] = m.type === 'checkbox' ? (m.checked ? '1' : '') : m.value;
            }

            return ki;
        }

        /* ---- Visszaállítás-panel -------------------------------------------- */
        function panelUzenet(szoveg, hiba) {
            if (!panel || !tartalom) {
                return;
            }

            tartalom.textContent = '';
            tartalom.appendChild(elem('p', 'sdh-uzenet ' + (hiba ? 'sdh-uzenet--hiba' : 'sdh-uzenet--siker'), szoveg));
            panel.hidden = false;
        }

        function sor(cim, ertek) {
            var li = elem('li');

            li.appendChild(elem('strong', '', cim + ': '));
            li.appendChild(document.createTextNode(ertek));

            return li;
        }

        function elonezetMegjelenit(adat) {
            if (!panel || !tartalom) {
                return;
            }

            var teljes = adat.tipus === 'teljes';

            tartalom.textContent = '';

            var lista = elem('ul', 'sdh-mentes-vissza__adatok');

            lista.appendChild(sor('Típus', teljes ? 'teljes mentés (adatok + beállítások)' : 'beállítás-csomag (adatok nélkül)'));
            lista.appendChild(sor('Készült', (adat.ido || '–') + (adat.verzio ? ' · verzió: ' + adat.verzio : '')));

            if (teljes) {
                lista.appendChild(sor('Tartalom', adat.tablak + ' tábla, ' + adat.sorok + ' sor'));
            }

            lista.appendChild(sor('Beállítások', String(adat.opciok) + ' db'));
            tartalom.appendChild(lista);

            tartalom.appendChild(elem('p', 'sdh-uzenet sdh-uzenet--figyelem',
                teljes
                    ? 'A teljes visszaállítás a mostani adatokat a mentés állapotára cseréli: a mentés óta felvett ügyfelek, lapok, pénztártételek elvesznek. ' +
                      'Előtte automatikusan biztonsági mentés készül a mostani állapotról – azzal a visszaállítás visszavonható.'
                    : 'Csak a beállítások íródnak vissza, az adatokhoz (ügyfelek, lapok, pénztár) a rendszer nem nyúl. Előtte biztonsági mentés készül.'));

            var gombok = elem('p', 'sdh-mentes-vissza__gombok');
            var allapot = elem('span', 'sdh-sugo');
            allapot.setAttribute('aria-live', 'polite');

            function vissza(mod) {
                return function (gomb) {
                    var osszes = gombok.querySelectorAll('button');

                    for (var i = 0; i < osszes.length; i++) {
                        osszes[i].disabled = true;
                    }

                    allapot.textContent = 'Visszaállítás folyamatban – ne zárd be az oldalt…';
                    kuld(gyoker, 'sdh_muhely_mentes_vissza', {token: adat.token, mod: mod}).then(function (v) {
                        allapot.textContent = uzenet(v, v && v.success ? 'Kész.' : 'A visszaállítás nem sikerült.');

                        if (v && v.success) {
                            window.setTimeout(function () { window.location.reload(); }, 2500);

                            return;
                        }

                        for (var i = 0; i < osszes.length; i++) {
                            osszes[i].disabled = false;
                        }
                    });
                };
            }

            if (teljes) {
                var g1 = elem('button', 'sdh-gomb sdh-gomb--elsodleges');
                g1.type = 'button';
                ketlepcsos(g1, 'Adatok és beállítások visszaállítása', 'Biztosan? Kattints újra', vissza('teljes'));
                gombok.appendChild(g1);
                gombok.appendChild(document.createTextNode(' '));
            }

            var g2 = elem('button', 'sdh-gomb sdh-gomb--vilagos');
            g2.type = 'button';
            ketlepcsos(g2, 'Csak a beállítások visszaállítása', 'Biztosan? Kattints újra', vissza('beallitasok'));
            gombok.appendChild(g2);
            gombok.appendChild(document.createTextNode(' '));

            var meg = elem('button', 'sdh-gomb sdh-gomb--vilagos', 'Mégse');
            meg.type = 'button';
            meg.addEventListener('click', function () {
                panel.hidden = true;
                tartalom.textContent = '';
            });
            gombok.appendChild(meg);
            gombok.appendChild(document.createTextNode(' '));
            gombok.appendChild(allapot);

            tartalom.appendChild(gombok);
            panel.hidden = false;

            if (panel.scrollIntoView) {
                panel.scrollIntoView({block: 'nearest'});
            }
        }

        function elonezet(mezokesz, hely, gomb) {
            if (gomb) {
                gomb.disabled = true;
            }

            if (hely) {
                hely.textContent = 'Megnyitás…';
            }

            kuld(gyoker, 'sdh_muhely_mentes_elonezet', mezokesz).then(function (v) {
                if (gomb) {
                    gomb.disabled = false;
                }

                if (!v || !v.success) {
                    var s = uzenet(v, 'A fájl nem nyitható meg.');

                    if (hely) {
                        hely.textContent = s;
                    }

                    panelUzenet(s, true);

                    return;
                }

                if (hely) {
                    hely.textContent = '';
                }

                elonezetMegjelenit(v.data);
            });
        }

        /* ---- Kattintások ---------------------------------------------------- */
        gyoker.addEventListener('click', function (e) {
            var cel = e.target.closest ? e.target.closest('button, a') : null;

            if (!cel) {
                return;
            }

            var kartya = cel.closest('[data-sdh-cel]');

            // Helyi mentés visszaállítása.
            if (cel.hasAttribute('data-sdh-vissza-helyi')) {
                var sorhely = cel.closest('li');
                var uz = sorhely ? sorhely.querySelector('.sdh-mentes-sor__uzenet') : null;

                if (!uz && sorhely) {
                    uz = elem('span', 'sdh-sugo sdh-mentes-sor__uzenet');
                    sorhely.appendChild(uz);
                }

                elonezet({forras: 'helyi', fajl: cel.getAttribute('data-sdh-vissza-helyi') || ''}, uz, cel);

                return;
            }

            // Külső hely mentésének visszaállítása.
            if (cel.hasAttribute('data-sdh-vissza-cel') && kartya) {
                var li = cel.closest('li');
                var hely = li ? li.querySelector('.sdh-mentes-sor__uzenet') : null;

                elonezet({forras: 'cel', cel: kartya.getAttribute('data-sdh-cel'), fajl: li ? li.getAttribute('data-fajl') || '' : ''}, hely, cel);

                return;
            }

            if (!kartya) {
                // Import fájlból.
                if (cel.hasAttribute('data-sdh-import-gomb')) {
                    var f = gyoker.querySelector('[data-sdh-import-fajl]');
                    var j = gyoker.querySelector('[data-sdh-import-jelszo]');
                    var h = gyoker.querySelector('[data-sdh-import-uzenet]');

                    if (!f || !f.files || !f.files[0]) {
                        if (h) {
                            h.textContent = 'Válaszd ki a fájlt.';
                        }

                        return;
                    }

                    elonezet({forras: 'feltoltes', jelszo: j ? j.value : '', fajl: f.files[0]}, h, cel);
                }

                return;
            }

            var uzhely = kartya.querySelector('[data-sdh-cel-uzenet]');

            if (cel.hasAttribute('data-sdh-cel-proba')) {
                cel.disabled = true;
                uzhely.textContent = 'Próba folyamatban…';
                kuld(gyoker, 'sdh_muhely_mentes_cel_proba', mezok(kartya)).then(function (v) {
                    cel.disabled = false;
                    uzhely.textContent = uzenet(v, v && v.success ? 'Rendben.' : 'A próba nem sikerült.');
                    uzhely.classList.toggle('sdh-mentes-hiba', !(v && v.success));
                });

                return;
            }

            if (cel.hasAttribute('data-sdh-cel-lista')) {
                var lista = kartya.querySelector('[data-sdh-cel-lista-hely]');

                cel.disabled = true;
                uzhely.textContent = 'Lista kérése…';
                kuld(gyoker, 'sdh_muhely_mentes_cel_lista', mezok(kartya)).then(function (v) {
                    cel.disabled = false;
                    uzhely.textContent = uzenet(v, '');
                    uzhely.classList.toggle('sdh-mentes-hiba', !(v && v.success));
                    lista.textContent = '';

                    if (!v || !v.success || !v.data || !v.data.lista || !v.data.lista.length) {
                        lista.hidden = true;

                        return;
                    }

                    v.data.lista.forEach(function (m) {
                        var li = elem('li');

                        li.setAttribute('data-fajl', m.fajl);
                        li.appendChild(elem('span', '', m.ido));
                        li.appendChild(elem('code', '', m.fajl));
                        li.appendChild(elem('span', '', m.meret));

                        var vg = elem('button', 'sdh-gomb sdh-gomb--vilagos', 'Visszaállítás…');
                        vg.type = 'button';
                        vg.setAttribute('data-sdh-vissza-cel', '1');
                        li.appendChild(vg);
                        li.appendChild(elem('span', 'sdh-sugo sdh-mentes-sor__uzenet'));
                        lista.appendChild(li);
                    });
                    lista.hidden = false;
                });

                return;
            }

            // Google Drive: összekapcsolás a Google eszközkódjával.
            if (cel.hasAttribute('data-sdh-gdrive-indit')) {
                gdriveIndit(kartya, cel);

                return;
            }

            if (cel.hasAttribute('data-sdh-gdrive-bont') && cel.getAttribute('data-sdh-biztos') !== '1') {
                cel.setAttribute('data-sdh-biztos', '1');
                cel.textContent = 'Biztosan? Kattints újra';
                window.setTimeout(function () {
                    cel.removeAttribute('data-sdh-biztos');
                    cel.textContent = 'Kapcsolat bontása';
                }, 6000);

                return;
            }

            if (cel.hasAttribute('data-sdh-gdrive-bont')) {
                cel.disabled = true;
                kuld(gyoker, 'sdh_muhely_mentes_gdrive_lekapcsol', {cel: 'gdrive'}).then(function (v) {
                    uzhely.textContent = uzenet(v, '');
                    window.setTimeout(function () { window.location.reload(); }, 900);
                });
            }
        });

        /* ---- Google Drive ---------------------------------------------------- */
        var gdriveIdo = null;

        function gdriveIndit(kartya, gomb) {
            var kodHely = kartya.querySelector('[data-sdh-gdrive-kod]');
            var allapot = kartya.querySelector('[data-sdh-gdrive-allapot]');

            window.clearTimeout(gdriveIdo);
            gomb.disabled = true;
            kodHely.hidden = false;
            kodHely.textContent = 'Kód kérése a Google-tól…';

            kuld(gyoker, 'sdh_muhely_mentes_gdrive_indit', mezok(kartya)).then(function (v) {
                gomb.disabled = false;

                if (!v || !v.success) {
                    kodHely.textContent = uzenet(v, 'Nem sikerült kódot kérni.');
                    kodHely.className = 'sdh-uzenet sdh-uzenet--hiba';

                    return;
                }

                var d = v.data;

                kodHely.className = 'sdh-uzenet sdh-uzenet--figyelem sdh-mentes-kod';
                kodHely.textContent = '';
                kodHely.appendChild(document.createTextNode('1. Nyisd meg: '));

                var a = elem('a', '', d.cim);

                a.href = d.cim;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                kodHely.appendChild(a);
                kodHely.appendChild(document.createTextNode(' – 2. Írd be ezt a kódot: '));
                kodHely.appendChild(elem('strong', 'sdh-mentes-kod__ertek', d.kod));
                kodHely.appendChild(document.createTextNode(' – 3. Engedélyezd a hozzáférést. Ez az oldal magától észreveszi.'));

                var varakozas = Math.max(3, parseInt(d.varakozas, 10) || 5);
                var lejar = Date.now() + (parseInt(d.lejar, 10) || 600) * 1000;

                function kerdez() {
                    if (Date.now() > lejar) {
                        kodHely.className = 'sdh-uzenet sdh-uzenet--hiba';
                        kodHely.textContent = 'A kód lejárt – kérj újat.';

                        return;
                    }

                    kuld(gyoker, 'sdh_muhely_mentes_gdrive_var', {cel: 'gdrive'}).then(function (r) {
                        var adat = r && r.success ? r.data : null;

                        if (!adat) {
                            kodHely.className = 'sdh-uzenet sdh-uzenet--hiba';
                            kodHely.textContent = uzenet(r, 'A kód lejárt – kérj újat.');

                            return;
                        }

                        if (adat.allapot === 'kesz') {
                            kodHely.className = 'sdh-uzenet sdh-uzenet--siker';
                            kodHely.textContent = adat.uzenet;

                            if (allapot) {
                                allapot.textContent = adat.uzenet;
                            }

                            window.setTimeout(function () { window.location.reload(); }, 1200);

                            return;
                        }

                        if (adat.allapot === 'hiba') {
                            kodHely.className = 'sdh-uzenet sdh-uzenet--hiba';
                            kodHely.textContent = adat.uzenet;

                            return;
                        }

                        if (adat.allapot === 'lassabban') {
                            varakozas += 5;
                        }

                        gdriveIdo = window.setTimeout(kerdez, varakozas * 1000);
                    });
                }

                gdriveIdo = window.setTimeout(kerdez, varakozas * 1000);
            });
        }
    }

    function betolt() {
        var lista = document.querySelectorAll('[data-sdh-mentes-gyoker]');

        for (var i = 0; i < lista.length; i++) {
            if (!lista[i].hasAttribute('data-sdh-mentes-kesz')) {
                lista[i].setAttribute('data-sdh-mentes-kesz', '1');
                indit(lista[i]);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', betolt);
    } else {
        betolt();
    }
})();
