/**
 * SDH Műhely – Szikra élete (0.59.4).
 *
 * Az asszisztens.js figurájára épül (window.SDH_SZIKRA): több száz arckifejezés
 * (mimika), szükségletek (éhség, pisi, kaki, fáradtság, büdösség, magány,
 * kalandvágy) és rövid jelenetek a képernyő alján – csirke, egér, támadó
 * madár, Pechás Pityu (akinek mindig rossz napja van), a Ködbáró, a barátnője
 * (Csilla), motor, autó, evés, tüsszentés, edzés…
 *
 * Minden szereplő saját rajz. Semmi nem zavar munka közben: jelenet csak akkor
 * indul, ha a figura szabad (nincs nyitva panel, buborék, túra), és pár
 * másodperce nem gépeltél vagy kattintottál; bármilyen kattintásra vagy
 * gépelésre azonnal abbamarad. Beállítások › Asszisztens: „Szikra élete" és
 * „Altesti humor" (pisi, kaki, fing, büdösség).
 */
(function () {
    'use strict';

    var B = window.SDH_MUHELY || {};
    var A = B.ai || {};

    if (!A.be || A.elet === false || window.SDH_SZIKRA_ELET) {
        return;
    }

    window.SDH_SZIKRA_ELET = true;

    var ALTESTI = A.altesti !== false;
    var TINTA = '#0b1020';
    var S = null;          // az asszisztens.js felülete
    var n = 1;             // a felület nagyítása (--sdh-n)
    var szinpad = null;
    var arcSvg = null;
    var kellekHely = null;
    var mimikaIdo = 0;
    var utolsoAktiv = Date.now();

    /* ================================================================ */
    /* Mimikák: szem × száj × szemöldök × extra                          */
    /* ================================================================ */

    // Az arc a figura 100×100-as terében: szemek (36.5|63.5, 44.5), száj (50, 66).
    var SZ = { b: 36.5, j: 63.5, y: 44.5 };

    function tag(t, a, belso) {
        var s = '<' + t;

        Object.keys(a).forEach(function (k) { s += ' ' + k + '="' + a[k] + '"'; });

        return s + (belso === undefined ? '/>' : '>' + belso + '</' + t + '>');
    }

    function vonal(d, w, szin) {
        return tag('path', { d: d, fill: 'none', stroke: szin || TINTA, 'stroke-width': w || 3, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' });
    }

    function kitolt(d, szin, w) {
        return tag('path', { d: d, fill: szin, stroke: TINTA, 'stroke-width': w === undefined ? 2 : w, 'stroke-linejoin': 'round' });
    }

    function kor(cx, cy, r, szin, w) {
        return tag('circle', { cx: cx, cy: cy, r: r, fill: szin, stroke: w ? TINTA : 'none', 'stroke-width': w || 0 });
    }

    function ellipszis(cx, cy, rx, ry, szin, w) {
        return tag('ellipse', { cx: cx, cy: cy, rx: rx, ry: ry, fill: szin, stroke: w ? TINTA : 'none', 'stroke-width': w || 0 });
    }

    /** Fehér szem pupillával (dx, dy: a pupilla eltolása; r: a pupilla mérete). */
    function szemGolyo(cx, cy, rx, ry, dx, dy, r) {
        return ellipszis(cx, cy, rx, ry, '#fff') + kor(cx + dx, cy + dy, r, TINTA) + kor(cx + dx - r * .35, cy + dy - r * .4, r * .38, '#fff');
    }

    var SZEM = {
        kerek: function (x, y) { return szemGolyo(x, y, 7.5, 10.5, 0, 1, 4.2); },
        nagy: function (x, y) { return szemGolyo(x, y, 9, 12.5, 0, 1, 4); },
        kicsi: function (x, y) { return szemGolyo(x, y, 6.5, 7.5, 0, 0, 1.8); },
        pont: function (x, y) { return kor(x, y + 1, 3.4, TINTA) + kor(x - 1.1, y - .2, 1.1, '#fff'); },
        fel: function (x, y) { return szemGolyo(x, y, 7.5, 10.5, 0, -5, 4); },
        le: function (x, y) { return szemGolyo(x, y, 7.5, 10.5, 0, 5, 4); },
        balra: function (x, y) { return szemGolyo(x, y, 7.5, 10.5, -3.6, 1, 4); },
        jobbra: function (x, y) { return szemGolyo(x, y, 7.5, 10.5, 3.6, 1, 4); },
        kancsal: function (x, y, o) { return szemGolyo(x, y, 7.5, 10.5, -o * 3.4, 1, 3.8); },
        csillog: function (x, y) { return szemGolyo(x, y, 8, 11, 0, 1, 5.4) + kor(x + 2.4, y + 3.6, 1.2, '#fff') + kor(x - 2.6, y + 3, .8, '#fff'); },
        konnyes: function (x, y) { return szemGolyo(x, y, 8.5, 11.5, 0, 1.5, 5.6) + kitolt('M' + (x - 7) + ' ' + (y + 6) + 'q7 5 14 0', 'rgba(143,211,255,.75)', 0); },
        felig: function (x, y) { return kitolt('M' + (x - 7.5) + ' ' + y + 'a7.5 9 0 0 0 15 0z', '#fff', 0) + kor(x, y + 3.5, 3.4, TINTA) + vonal('M' + (x - 8) + ' ' + y + 'h16', 3); },
        almos: function (x, y) { return kitolt('M' + (x - 7.5) + ' ' + (y + 2) + 'a7.5 7 0 0 0 15 0z', '#fff', 0) + kor(x, y + 5, 3, TINTA) + vonal('M' + (x - 8) + ' ' + (y + 2) + 'q8 2 16 0', 3); },
        duhos: function (x, y, o) {
            var k = x - 7.5;
            var v = x + 7.5;
            var fk = o < 0 ? y - 7 : y - 1;
            var fv = o < 0 ? y - 1 : y - 7;

            return kitolt('M' + k + ' ' + fk + 'L' + v + ' ' + fv + 'L' + v + ' ' + (y + 2) + 'A7.5 8 0 0 1 ' + k + ' ' + (y + 2) + 'z', '#fff', 0) +
                kor(x, y + 3, 3.4, TINTA) + vonal('M' + (k - 1) + ' ' + (fk - (o < 0 ? .4 : -.4)) + 'L' + (v + 1) + ' ' + fv, 3.2);
        },
        gyanakvo: function (x, y) { return kitolt('M' + (x - 7.5) + ' ' + (y - 1) + 'h15v3a7.5 7.5 0 0 1-15 0z', '#fff', 0) + kor(x + 2, y + 3, 3.2, TINTA) + vonal('M' + (x - 8.5) + ' ' + (y - 1.5) + 'h17', 3.2); },
        piros: function (x, y) { return szemGolyo(x, y, 7.5, 10.5, 0, 1, 3.6) + vonal('M' + (x - 6) + ' ' + (y + 2) + 'l3-1M' + (x + 6) + ' ' + (y - 1) + 'l-3 1M' + (x - 5) + ' ' + (y - 5) + 'l2 2', 1, '#e11d48'); },
        ures: function (x, y) { return ellipszis(x, y, 8, 9.5, '#fff', 1.6) + kor(x, y, 1.6, TINTA); },
        mosolyog: function (x, y) { return vonal('M' + (x - 7) + ' ' + (y + 3) + 'Q' + x + ' ' + (y - 7) + ' ' + (x + 7) + ' ' + (y + 3), 3.2); },
        csukva: function (x, y) { return vonal('M' + (x - 7) + ' ' + y + 'Q' + x + ' ' + (y + 6) + ' ' + (x + 7) + ' ' + y, 3); },
        sir: function (x, y) { return vonal('M' + (x - 7) + ' ' + y + 'h14', 3.2) + tag('rect', { x: x - 2.5, y: y + 1.5, width: 5, height: 28, rx: 2.5, fill: 'rgba(143,211,255,.85)' }); },
        x: function (x, y) { return vonal('M' + (x - 5.5) + ' ' + (y - 5.5) + 'l11 11M' + (x + 5.5) + ' ' + (y - 5.5) + 'l-11 11', 3.2); },
        spiral: function (x, y) { return vonal('M' + x + ' ' + y + 'm0-1.5a1.5 1.5 0 1 1-1.5 1.5a3.5 3.5 0 1 1 3.5 3.5a5.5 5.5 0 1 1 5.5-5.5', 1.8); },
        sziv: function (x, y) { return kitolt('M' + x + ' ' + (y + 7) + 'c-9-6-10-12-6-15 3-2 5 0 6 2 1-2 3-4 6-2 4 3 3 9-6 15z', '#ff3d6e', 1.6); },
        csillag: function (x, y) { return kitolt('M' + x + ' ' + (y - 8) + 'l2.4 5.2 5.6.6-4.2 3.8 1.2 5.6-5-2.9-5 2.9 1.2-5.6-4.2-3.8 5.6-.6z', '#ffd23f', 1.4); },
        dollar: function (x, y) { return tag('text', { x: x, y: y + 6, 'text-anchor': 'middle', 'font-size': 18, 'font-weight': 900, fill: '#16a34a', stroke: TINTA, 'stroke-width': .8 }, '$'); },
        szorit: function (x, y, o) { return vonal(o < 0 ? 'M' + (x - 6) + ' ' + (y - 6) + 'L' + (x + 5) + ' ' + y + 'L' + (x - 6) + ' ' + (y + 6) : 'M' + (x + 6) + ' ' + (y - 6) + 'L' + (x - 5) + ' ' + y + 'L' + (x + 6) + ' ' + (y + 6), 3.2); },
        vonal: function (x, y) { return vonal('M' + (x - 7) + ' ' + (y + 1) + 'h14', 3.2); },
        kacsint: function (x, y, o) { return o < 0 ? SZEM.kerek(x, y, o) : SZEM.mosolyog(x, y, o); }
    };

    var PUPILLAS = ['kerek', 'nagy', 'fel', 'le', 'balra', 'jobbra', 'kancsal', 'csillog', 'kicsi', 'konnyes'];

    var SZAJ = {
        mosoly: function () { return vonal('M40 63Q50 72 60 63'); },
        vigyor: function () { return kitolt('M38 62Q50 79 62 62z', TINTA, 2.4) + ellipszis(50, 70.5, 6, 2.6, '#ff8fb1'); },
        fogas: function () { return kitolt('M38 62Q50 77 62 62z', '#fff', 2.6) + vonal('M44 63v5M50 63v6.5M56 63v5', 1.4); },
        szomoru: function () { return vonal('M40 70Q50 61 60 70'); },
        o: function () { return ellipszis(50, 67, 5, 6, TINTA); },
        kis_o: function () { return kor(50, 66, 2.6, TINTA); },
        egyenes: function () { return vonal('M42 66h16'); },
        hullam: function () { return vonal('M39 66q2.75-3 5.5 0t5.5 0 5.5 0 5.5 0', 2.6); },
        nyelv: function () { return vonal('M40 63Q50 70 60 63') + kitolt('M45 66q5 11 10 0', '#ff6f9c', 2); },
        grimasz: function () { return tag('rect', { x: 38, y: 61, width: 24, height: 9, rx: 3, fill: '#fff', stroke: TINTA, 'stroke-width': 2.4 }) + vonal('M38 65.5h24M44 61v9M50 61v9M56 61v9', 1.3); },
        puszi: function () { return kitolt('M47 62q5 1 3 4 4 2 0 5-2 0-3-1', 'none', 0) + vonal('M47.5 62.5q4 1 2.5 3.5 4 2.5-.5 5', 2.6) + kor(53, 66, 1.4, '#ff3d6e'); },
        ferde: function () { return vonal('M42 67Q53 70 60 61'); },
        asit: function () { return ellipszis(50, 68, 6.5, 9, TINTA) + ellipszis(50, 73, 4, 2.4, '#ff8fb1'); },
        ordit: function () { return ellipszis(50, 68, 11, 9, TINTA) + tag('rect', { x: 42, y: 59.6, width: 16, height: 3.6, rx: 1.4, fill: '#fff' }) + ellipszis(50, 73.5, 6, 3, '#ff8fb1'); },
        macska: function () { return vonal('M41 64q4.5 5 9 0 4.5 5 9 0', 2.6); },
        vampir: function () { return vonal('M40 63Q50 70 60 63') + kitolt('M44 65.2l2 5 2-4.3zM56 65.2l-2 5-2-4.3z', '#fff', 1.2); },
        nyal: function () { return vonal('M40 63Q50 72 60 63') + kitolt('M57 66.5c-1 2.5-1.6 4-1.6 5a1.6 1.6 0 0 0 3.2 0c0-1-.6-2.5-1.6-5z', '#8fd3ff', 1); },
        fityul: function () { return tag('circle', { cx: 55, cy: 66, r: 2.6, fill: 'none', stroke: TINTA, 'stroke-width': 2.4 }); },
        dacos: function () { return vonal('M42 68Q50 63 58 68') + vonal('M45 69q5 3 10 0', 2); },
        harap: function () { return vonal('M42 66h16') + tag('rect', { x: 46.5, y: 66, width: 3, height: 3, fill: '#fff', stroke: TINTA, 'stroke-width': 1 }); },
        csucsor: function () { return ellipszis(50, 66.5, 3.4, 2.4, '#ff6f9c', 1.8); },
        ruppent: function () { return vonal('M40 68l3-2 3 2 3-2 3 2 3-2 3 2 3-2', 2.4); },
        tele: function () { return vonal('M45 66h10', 2.6) + kor(36, 64, 5, 'rgba(255,255,255,.3)', 1.2) + kor(64, 64, 5, 'rgba(255,255,255,.3)', 1.2); }
    };

    var SZEMOLDOK = {
        nincs: function () { return ''; },
        normal: function () { return vonal('M30 30q6.5-3 13 0M57 30q6.5-3 13 0', 2.6); },
        fel: function () { return vonal('M30 25q6.5-4 13 0M57 25q6.5-4 13 0', 2.6); },
        duhos: function () { return vonal('M29 27l14 5M71 27l-14 5', 3); },
        szomoru: function () { return vonal('M30 31l13-5M70 31l-13-5', 2.6); },
        egyfel: function () { return vonal('M30 30q6.5-3 13 0M57 24q6.5-4 13 0', 2.6); },
        vastag: function () { return vonal('M30 29q6.5-4 13 0M57 29q6.5-4 13 0', 5); },
        osszehuz: function () { return vonal('M32 31h12M56 31h12', 3); }
    };

    var EXTRA = {
        '': function () { return ''; },
        pir: function () { return ellipszis(23, 60, 6, 3.4, 'rgba(255,90,140,.6)') + ellipszis(77, 60, 6, 3.4, 'rgba(255,90,140,.6)'); },
        izzad: function () { return kitolt('M84 22c-3 5-4.6 7.6-4.6 10a4.6 4.6 0 0 0 9.2 0c0-2.4-1.6-5-4.6-10z', '#8fd3ff', 1.6); },
        konny: function () { return kitolt('M31 57c-2 4-3 6-3 7.5a3 3 0 0 0 6 0c0-1.5-1-3.5-3-7.5zM69 57c-2 4-3 6-3 7.5a3 3 0 0 0 6 0c0-1.5-1-3.5-3-7.5z', '#8fd3ff', 1.2); },
        goz: function () { return '<g class="sdh-sz-goz">' + kitolt('M-2 18c-6-2-6-10 1-10 1-6 9-6 10 0 6 0 6 8 0 9z', '#fff', 1.6) + kitolt('M102 18c6-2 6-10-1-10-1-6-9-6-10 0-6 0-6 8 0 9z', '#fff', 1.6) + '</g>'; },
        szivek: function () { return '<g class="sdh-sz-lebeg">' + kitolt('M86 14c-6-4-7-8-4-10 2-1.4 3.4 0 4 1.4.6-1.4 2-2.8 4-1.4 3 2 2 6-4 10z', '#ff3d6e', 1.2) + kitolt('M14 6c-4.5-3-5-6-3-7.5 1.5-1 2.6 0 3 1 .4-1 1.5-2 3-1 2 1.5 1.5 4.5-3 7.5z', '#ff3d6e', 1.2) + '</g>'; },
        zzz: function () { return tag('text', { x: 84, y: 10, 'font-size': 15, 'font-weight': 900, fill: '#fff', stroke: TINTA, 'stroke-width': .8, class: 'sdh-sz-lebeg' }, 'z<tspan font-size="11" dy="-6">z</tspan><tspan font-size="8" dy="-5">z</tspan>'); },
        kerdo: function () { return tag('text', { x: 92, y: 14, 'font-size': 24, 'font-weight': 900, fill: '#ffd23f', stroke: TINTA, 'stroke-width': 1, class: 'sdh-sz-lebeg' }, '?'); },
        felkialt: function () { return tag('text', { x: 92, y: 14, 'font-size': 26, 'font-weight': 900, fill: '#ff5f5f', stroke: TINTA, 'stroke-width': 1, class: 'sdh-sz-lebeg' }, '!'); },
        takony: function () { return tag('circle', { cx: 52, cy: 59, r: 4.5, fill: 'rgba(170,240,150,.75)', stroke: TINTA, 'stroke-width': 1.2, class: 'sdh-sz-takony' }); },
        korte: function () { return '<g class="sdh-sz-lebeg">' + kitolt('M50 -22a9 9 0 0 0-5 16v4h10v-4a9 9 0 0 0-5-16z', '#ffe066', 1.8) + vonal('M45 1h10M46 4h8', 1.6) + vonal('M36 -24l-4-4M64 -24l4-4M50 -36v-5', 2, '#ffd23f') + '</g>'; },
        zold: function () { return kor(50, 50, 50, 'rgba(90,200,90,.38)'); },
        piros: function () { return kor(50, 50, 50, 'rgba(255,60,60,.32)'); },
        kek: function () { return kor(50, 50, 50, 'rgba(120,170,255,.38)') + vonal('M20 82l-3 6M30 86l-2 6M74 84l2 6', 1.6, '#bfe6ff'); },
        csillagok: function () { return '<g class="sdh-sz-kering">' + SZEM.csillag(14, 4) + SZEM.csillag(86, 4) + SZEM.csillag(50, -10) + '</g>'; },
        legyek: function () { return '<g class="sdh-sz-kering">' + kor(10, 10, 2.4, TINTA) + kor(92, 20, 2.4, TINTA) + kor(50, -8, 2.4, TINTA) + vonal('M-4 30q4-4 8 0t8 0M96 40q4-4 8 0t8 0', 1.6, '#4ade80') + '</g>'; },
        hang: function () { return tag('text', { x: 82, y: 22, 'font-size': 18, fill: '#fff', stroke: TINTA, 'stroke-width': .8, class: 'sdh-sz-lebeg' }, '♪<tspan dx="2" dy="-8">♫</tspan>'); }
    };

    // Alap-érzelmek: [név, szem, száj, szemöldök, extra].
    var CSALADOK = [
        ['boldog', 'kerek', 'mosoly', 'normal', ''], ['vigyorgó', 'mosolyog', 'vigyor', 'fel', ''],
        ['nevető', 'szorit', 'vigyor', 'fel', ''], ['röhögő', 'szorit', 'ordit', 'fel', 'konny'],
        ['szerelmes', 'sziv', 'mosoly', 'fel', 'pir'], ['csillogó szemű', 'csillog', 'mosoly', 'fel', ''],
        ['büszke', 'felig', 'ferde', 'normal', ''], ['laza', 'felig', 'mosoly', 'normal', ''],
        ['kacsintó', 'kacsint', 'ferde', 'egyfel', ''], ['huncut', 'balra', 'ferde', 'egyfel', ''],
        ['meglepett', 'nagy', 'o', 'fel', ''], ['döbbent', 'ures', 'ordit', 'fel', 'felkialt'],
        ['ijedt', 'kicsi', 'hullam', 'szomoru', 'izzad'], ['rémült', 'ures', 'ordit', 'fel', 'kek'],
        ['szomorú', 'le', 'szomoru', 'szomoru', ''], ['síró', 'sir', 'ordit', 'szomoru', ''],
        ['pityergő', 'konnyes', 'dacos', 'szomoru', ''], ['dühös', 'duhos', 'grimasz', 'duhos', 'goz'],
        ['tomboló', 'duhos', 'ordit', 'duhos', 'piros'], ['morcos', 'duhos', 'szomoru', 'duhos', ''],
        ['gyanakvó', 'gyanakvo', 'egyenes', 'egyfel', ''], ['unott', 'vonal', 'egyenes', 'normal', ''],
        ['álmos', 'almos', 'asit', 'szomoru', 'zzz'], ['fáradt', 'piros', 'hullam', 'szomoru', ''],
        ['alvó', 'csukva', 'kis_o', 'normal', 'zzz'], ['szédült', 'spiral', 'hullam', 'fel', 'csillagok'],
        ['kiütött', 'x', 'nyelv', 'nincs', 'csillagok'], ['beteg', 'almos', 'hullam', 'szomoru', 'zold'],
        ['undorodó', 'szorit', 'hullam', 'duhos', 'zold'], ['büdös', 'gyanakvo', 'grimasz', 'duhos', 'legyek'],
        ['éhes', 'fel', 'nyal', 'fel', ''], ['jóllakott', 'mosolyog', 'tele', 'normal', 'pir'],
        ['pénzéhes', 'dollar', 'vigyor', 'fel', ''], ['sztár', 'csillag', 'fogas', 'fel', 'csillagok'],
        ['gondolkodó', 'fel', 'csucsor', 'egyfel', 'kerdo'], ['ötletes', 'fel', 'vigyor', 'fel', 'korte'],
        ['értetlen', 'kancsal', 'hullam', 'egyfel', 'kerdo'], ['zavarban lévő', 'balra', 'harap', 'szomoru', 'pir'],
        ['szégyenlős', 'le', 'mosoly', 'szomoru', 'pir'], ['puszis', 'mosolyog', 'puszi', 'fel', 'szivek'],
        ['fütyörésző', 'fel', 'fityul', 'fel', 'hang'], ['ártatlan', 'fel', 'fityul', 'fel', ''],
        ['cuki', 'pont', 'macska', 'normal', 'pir'], ['vámpír', 'duhos', 'vampir', 'duhos', ''],
        ['erőlködő', 'szorit', 'grimasz', 'duhos', 'piros'], ['megkönnyebbült', 'mosolyog', 'o', 'fel', 'izzad'],
        ['fázó', 'kerek', 'ruppent', 'szomoru', 'kek'], ['tüsszentő', 'szorit', 'o', 'fel', 'takony'],
        ['dacos', 'gyanakvo', 'dacos', 'duhos', ''], ['eltökélt', 'duhos', 'egyenes', 'duhos', '']
    ];

    // Árnyalatok: minden alap-érzelem ezekkel is megjelenhet.
    var ARNYALATOK = [
        ['', {}], ['pirulva', { x: 'pir' }], ['izzadva', { x: 'izzad' }], ['balra sandítva', { e: 'balra' }],
        ['jobbra sandítva', { e: 'jobbra' }], ['felnézve', { e: 'fel' }], ['kérdőn', { x: 'kerdo' }],
        ['felhúzott szemöldökkel', { b: 'fel' }], ['nyelvet öltve', { m: 'nyelv' }], ['könnyesen', { x: 'konny' }],
        ['vastag szemöldökkel', { b: 'vastag' }], ['tátott szájjal', { m: 'o' }]
    ];

    var MIMIKAK = [];
    var MIMIKA_NEV = {};

    (function () {
        var latott = {};

        CSALADOK.forEach(function (c) {
            ARNYALATOK.forEach(function (a) {
                var m = { nev: c[0] + (a[0] ? ', ' + a[0] : ''), e: c[1], m: c[2], b: c[3], x: c[4] };
                var mod = a[1];

                // Szemet csak pupillás szemen lehet „sandítani" – a szív- vagy X-szem megmarad.
                if (mod.e && PUPILLAS.indexOf(m.e) === -1) {
                    return;
                }

                ['e', 'm', 'b', 'x'].forEach(function (k) {
                    if (mod[k] !== undefined) {
                        m[k] = mod[k];
                    }
                });

                var kulcs = [m.e, m.m, m.b, m.x].join('|');

                if (latott[kulcs]) {
                    return;
                }

                latott[kulcs] = true;
                MIMIKAK.push(m);

                if (!a[0]) {
                    MIMIKA_NEV[c[0]] = m;
                }
            });
        });
    }());

    /** A mimika SVG-je. */
    function arcRajz(m) {
        var szin = m.x === 'zold' || m.x === 'piros' || m.x === 'kek' ? EXTRA[m.x]() : '';
        var tobbi = szin ? '' : (EXTRA[m.x] || EXTRA[''])();

        return szin + (SZEMOLDOK[m.b] || SZEMOLDOK.nincs)() +
            (SZEM[m.e] || SZEM.kerek)(SZ.b, SZ.y, -1) + (SZEM[m.e] || SZEM.kerek)(SZ.j, SZ.y, 1) +
            (SZAJ[m.m] || SZAJ.mosoly)() + tobbi;
    }

    /** Mimika a figurára (név vagy objektum); ms után visszaáll a megszokott arcra. */
    function mimika(m, ms) {
        if (!S || !arcSvg) {
            return;
        }

        m = typeof m === 'string' ? (MIMIKA_NEV[m] || MIMIKAK.filter(function (x) { return x.nev === m; })[0]) : m;

        window.clearTimeout(mimikaIdo);

        if (!m) {
            S.gyoker.classList.remove('is-mimika');
            arcSvg.innerHTML = '';

            return;
        }

        arcSvg.innerHTML = arcRajz(m);
        arcSvg.setAttribute('data-mimika', m.nev);
        S.gyoker.classList.add('is-mimika');

        if (ms) {
            mimikaIdo = window.setTimeout(function () { mimika(null); }, ms);
        }
    }

    function veletlenMimika() {
        return MIMIKAK[Math.floor(Math.random() * MIMIKAK.length)];
    }

    /* ================================================================ */
    /* Szereplők és kellékek (saját rajzok)                              */
    /* ================================================================ */

    function g(belso) {
        return '<g stroke="' + TINTA + '" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round">' + belso + '</g>';
    }

    var RAJZ = {
        csirke: [64, 64, g(
            '<g class="sdh-sz-labak"><path d="M26 52l-2 9M36 52l1 9" stroke="#f59e0b" stroke-width="2.8"/></g>' +
            '<path d="M8 22c2 6 4 10 8 12" fill="none"/><path d="M6 26c3 4 6 7 10 8" fill="none"/>' +
            '<path d="M12 32c0-6 6-8 10-6 6 2 10 4 14 0 2-8 6-14 12-14 7 0 10 7 8 14-1 4-2 6-2 10 0 10-9 18-22 18-12 0-20-8-20-22z" fill="#fff"/>' +
            '<path d="M22 36c4 7 11 8 16 3" fill="none"/>' +
            '<path d="M41 12c0-4 3-6 5-4 1-3 5-3 6 0 3-1 5 2 3 5z" fill="#ef4444"/>' +
            '<circle cx="50" cy="20" r="1.9" fill="' + TINTA + '" stroke="none"/>' +
            '<path class="sdh-sz-csor" d="M55 21l8 3-7 3z" fill="#f59e0b"/>' +
            '<path d="M54 27c1 3 0 6-2 6s-2-3 0-6z" fill="#ef4444"/>')],
        eger: [64, 48, g(
            '<path d="M10 38C2 38-2 30 6 28" fill="none"/>' +
            '<path d="M10 38c0-10 10-16 22-16 10 0 18 6 24 14-6 3-14 4-22 4H12c-1 0-2-1-2-2z" fill="#9aa3b5"/>' +
            '<circle cx="36" cy="22" r="7" fill="#9aa3b5"/><circle cx="36" cy="22" r="3.6" fill="#ffb3c7" stroke="none"/>' +
            '<circle cx="47" cy="30" r="1.7" fill="' + TINTA + '" stroke="none"/><circle cx="56.5" cy="36" r="1.9" fill="#ff7aa8"/>' +
            '<path d="M54 34l8-3M54 37l8 1" stroke-width="1.2"/>' +
            '<g class="sdh-sz-labak"><path d="M22 40l-1 5M38 40l1 5"/></g>')],
        madar: [64, 56, g(
            '<path class="sdh-sz-szarny" d="M26 26C18 10 8 8 2 12c8 2 12 8 16 16z" fill="#343b52"/>' +
            '<path d="M12 32c6-8 20-12 32-6l8-2-4 6c0 8-10 14-22 12-8-1-13-5-14-10z" fill="#343b52"/>' +
            '<path d="M48 26l14 3-13 4z" fill="#ffb02e"/>' +
            '<circle cx="44" cy="26" r="2.8" fill="#fff"/><circle cx="44.8" cy="26.2" r="1.4" fill="' + TINTA + '" stroke="none"/>' +
            '<path d="M39 20l8 4"/>' +
            '<path class="sdh-sz-szarny sdh-sz-szarny--2" d="M28 30c-4 14-14 18-22 16 8-4 10-10 12-18z" fill="#2a3045"/>' +
            '<path d="M12 34l-10 2 9 3" fill="#2a3045"/>')],
        // Pechás Pityu: zöld kötött sapka bojttal, nagy kerek szemüveg, csíkos sál, sárga pulóver.
        pityu: [60, 80, g(
            '<path d="M25 64v11M37 64v11" stroke-width="4.2"/><path d="M21 76h8M34 76h8" stroke-width="3.6"/>' +
            '<path d="M22 50l-8 12M40 50l8 12" stroke-width="3.6"/>' +
            '<rect x="20" y="42" width="22" height="24" rx="7" fill="#facc15"/>' +
            '<rect x="20" y="58" width="22" height="8" rx="2" fill="#3b82f6"/>' +
            '<circle cx="31" cy="28" r="14" fill="#f5c9a0"/>' +
            '<path d="M17 24c0-13 28-13 28 0z" fill="#22c55e"/><path d="M17 24h28" stroke-width="3"/>' +
            '<path d="M21 18h4M28 15h4M35 18h4" stroke="#bbf7d0" stroke-width="1.6"/>' +
            '<circle cx="31" cy="9" r="3.6" fill="#22c55e"/>' +
            '<circle cx="25.5" cy="29" r="5" fill="#fff"/><circle cx="36.5" cy="29" r="5" fill="#fff"/><path d="M30.5 29h1"/>' +
            '<circle cx="26.5" cy="29.5" r="1.6" fill="' + TINTA + '" stroke="none"/><circle cx="37.5" cy="29.5" r="1.6" fill="' + TINTA + '" stroke="none"/>' +
            '<path class="sdh-sz-pityu-szaj" d="M27 36q4 3 8 0" fill="none"/>' +
            '<path d="M18 41h26l-2 5H20z" fill="#ef4444"/><path d="M24 41l-1 5M31 41v5M38 41l1 5" stroke="#fff" stroke-width="1.6"/>' +
            '<path d="M39 44l5 11" stroke="#ef4444" stroke-width="4.4"/>')],
        szellem: [60, 76, g(
            '<ellipse cx="31" cy="6" rx="10" ry="3" fill="none" stroke="#ffd23f" stroke-width="2.4"/>' +
            '<path d="M14 70V30a17 17 0 0 1 34 0v40l-6-5-5 5-6-5-6 5-5-5z" fill="rgba(255,255,255,.88)"/>' +
            '<path d="M17 24c0-12 28-12 28 0z" fill="#22c55e" opacity=".8"/>' +
            '<circle cx="25" cy="32" r="2" fill="' + TINTA + '" stroke="none"/><circle cx="37" cy="32" r="2" fill="' + TINTA + '" stroke="none"/>' +
            '<path d="M27 40q4-3 8 0" fill="none"/>')],
        // A Ködbáró: lila ködfelhő cilinderrel, monoklival, bajusszal és villámpálcával.
        kodbaro: [80, 72, g(
            '<path d="M14 58c-8 0-10-10-3-13-2-9 8-14 14-9 3-8 15-9 19-1 6-4 15 0 14 8 8 1 9 13 1 15z" fill="#7c5cbf"/>' +
            '<path d="M18 52c4 2 8 2 12 0M40 54c4 2 8 2 12 0" fill="none" stroke="#a68ae0" stroke-width="1.6"/>' +
            '<rect x="26" y="10" width="22" height="18" rx="2" fill="#1f2333"/><path d="M19 28h36" stroke-width="3.6"/>' +
            '<path d="M26 22h22" stroke="#e11d48" stroke-width="3"/>' +
            '<circle cx="31" cy="40" r="3.2" fill="#fff"/><circle cx="31.6" cy="40.4" r="1.6" fill="' + TINTA + '" stroke="none"/>' +
            '<circle cx="45" cy="40" r="5.4" fill="rgba(255,255,255,.45)"/><circle cx="45" cy="40.4" r="1.7" fill="' + TINTA + '" stroke="none"/>' +
            '<path d="M49 44l3 10" stroke-width="1.2"/><path d="M27 34l8 2M40 35l10-2"/>' +
            '<path d="M29 48c3-3 6-3 8 0 2-3 5-3 8 0" fill="none"/>' +
            '<path class="sdh-sz-baro-szaj" d="M33 51q4 3 8 0" fill="none"/>' +
            '<g class="sdh-sz-bot"><path d="M64 30l10 34" stroke-width="3.6"/><circle cx="63" cy="28" r="3.8" fill="#ffd23f"/></g>')],
        // Csilla, a barátnő: rózsaszín szikra masnival és pillákkal.
        csilla: [64, 64, g(
            '<g class="sdh-sz-labak"><path d="M24 54l-2 8M40 54l2 8"/></g>' +
            '<ellipse cx="32" cy="36" rx="20" ry="19" fill="#ff8fc4"/>' +
            '<ellipse cx="26" cy="27" rx="6" ry="3" fill="rgba(255,255,255,.5)" stroke="none"/>' +
            '<path d="M30 17c-6-6-14-4-12 3 2 5 10 3 12-1zM34 17c6-6 14-4 12 3-2 5-10 3-12-1z" fill="#ff3d8e"/><circle cx="32" cy="18" r="3" fill="#ff3d8e"/>' +
            '<ellipse cx="25" cy="35" rx="4" ry="5.5" fill="#fff"/><ellipse cx="39" cy="35" rx="4" ry="5.5" fill="#fff"/>' +
            '<circle cx="25.6" cy="36" r="2.3" fill="' + TINTA + '" stroke="none"/><circle cx="39.6" cy="36" r="2.3" fill="' + TINTA + '" stroke="none"/>' +
            '<path d="M21 30l-3-2M22 29l-1-3M43 30l3-2M42 29l1-3" stroke-width="1.6"/>' +
            '<ellipse cx="19" cy="43" rx="3" ry="1.6" fill="#ff5f8f" stroke="none" opacity=".6"/><ellipse cx="45" cy="43" rx="3" ry="1.6" fill="#ff5f8f" stroke="none" opacity=".6"/>' +
            '<path d="M27 44q5 5 10 0" fill="#e11d48"/>')],
        motor: [96, 56, g(
            '<circle class="sdh-sz-kerek" cx="18" cy="42" r="11" fill="#1f2333"/><circle cx="18" cy="42" r="4" fill="#9aa3b5"/>' +
            '<circle class="sdh-sz-kerek" cx="78" cy="42" r="11" fill="#1f2333"/><circle cx="78" cy="42" r="4" fill="#9aa3b5"/>' +
            '<path d="M18 42 30 22h10l10 12h18l10 8" fill="none" stroke-width="3"/>' +
            '<path d="M34 30c4-8 26-8 32 0l-4 6H38z" fill="#ef4444"/>' +
            '<path d="M30 22l-6-8h-6" stroke-width="3"/><circle cx="16" cy="26" r="3" fill="#ffd23f"/>' +
            '<path d="M60 36h24l-2 6H62z" fill="#9aa3b5"/>')],
        auto: [110, 60, g(
            '<path d="M6 46v-10c0-4 3-7 8-8l14-3 12-12h34l14 14c8 1 14 4 14 10v9z" fill="#ef4444"/>' +
            '<path d="M44 18h12v11H33zM60 18h12l11 11H60z" fill="#bfe6ff"/>' +
            '<circle class="sdh-sz-kerek" cx="28" cy="48" r="9" fill="#1f2333"/><circle cx="28" cy="48" r="3.5" fill="#9aa3b5"/>' +
            '<circle class="sdh-sz-kerek" cx="86" cy="48" r="9" fill="#1f2333"/><circle cx="86" cy="48" r="3.5" fill="#9aa3b5"/>' +
            '<path d="M6 36h6" stroke="#ffd23f" stroke-width="4"/><path d="M100 38h4" stroke="#ff5f5f" stroke-width="4"/>')],
        wc: [52, 60, g(
            '<rect x="28" y="6" width="20" height="24" rx="3" fill="#fff"/><path d="M32 10h12" />' +
            '<path d="M6 30h40c0 12-6 18-14 20v6H18v-6C10 48 6 42 6 30z" fill="#fff"/>' +
            '<path d="M4 28h44" stroke-width="3"/>')],
        bokor: [60, 46, g('<path d="M6 44c-6-8 0-18 8-16 0-10 12-14 18-8 6-8 20-4 18 6 8 0 12 12 4 18z" fill="#22c55e"/><path d="M20 32l4 4M38 28l-3 5M30 38l2 3" stroke="#14532d" stroke-width="1.6"/>')],
        zuhany: [70, 100, g('<path d="M58 100V8H34" fill="none" stroke-width="3.4" stroke="#9aa3b5"/><path d="M22 8h22l-4 8H26z" fill="#9aa3b5"/>') + '<g class="sdh-sz-viz" fill="#8fd3ff">' +
            '<rect x="26" y="20" width="2" height="8" rx="1"/><rect x="32" y="26" width="2" height="8" rx="1"/><rect x="38" y="18" width="2" height="8" rx="1"/><rect x="29" y="36" width="2" height="8" rx="1"/><rect x="36" y="40" width="2" height="8" rx="1"/></g>'],
        ullo: [56, 40, g('<path d="M4 6h48l-6 10H36v8l10 12H10l10-12v-8H10z" fill="#4b5563"/>') + '<text x="28" y="15" text-anchor="middle" font-size="9" font-weight="900" fill="#fff">1 t</text>'],
        banan: [40, 20, g('<path d="M4 16c6-8 14-10 18-6 4-6 10-6 14 0-6 0-10 2-14 6-6-2-12-2-18 0z" fill="#facc15"/>')],
        villam: [30, 70, g('<path d="M18 0 4 36h10L8 70l20-42H18l8-28z" fill="#ffd23f"/>')],
        felho: [70, 36, g('<path d="M12 34C2 34 0 22 10 20 8 10 22 6 28 14c4-10 20-10 22 2 10-2 16 8 10 18z" fill="#6b7280"/>')],
        burger: [40, 34, g('<path d="M4 16C4 4 36 4 36 16z" fill="#f59e0b"/><path d="M3 18h34" stroke="#22c55e" stroke-width="4"/><rect x="4" y="20" width="32" height="6" rx="3" fill="#7c2d12"/><path d="M4 28h32c0 4-4 5-8 5H12c-4 0-8-1-8-5z" fill="#f59e0b"/><path d="M14 9h1M22 7h1M28 10h1" stroke="#fff"/>')],
        uditok: [24, 40, g('<rect x="4" y="4" width="16" height="32" rx="3" fill="#e11d48"/><path d="M4 12h16M4 28h16" stroke="#fff" stroke-width="1.6"/><path d="M10 2h6" stroke-width="2.6"/>')],
        sulyzo: [70, 24, g('<rect x="6" y="2" width="8" height="20" rx="2" fill="#4b5563"/><rect x="56" y="2" width="8" height="20" rx="2" fill="#4b5563"/><path d="M14 12h42" stroke-width="4"/>')],
        telefon: [22, 36, g('<rect x="3" y="2" width="16" height="32" rx="3" fill="#1f2333"/><rect x="5.5" y="6" width="11" height="20" rx="1" fill="#8fd3ff" stroke="none"/>')],
        virag: [26, 44, g('<path d="M13 44V18" stroke="#16a34a" stroke-width="2.6"/><path d="M13 30c-6-2-8-6-6-8 4 0 6 4 6 8z" fill="#22c55e"/>' +
            '<circle cx="13" cy="7" r="5" fill="#ff5f8f"/><circle cx="6" cy="13" r="5" fill="#ff5f8f"/><circle cx="20" cy="13" r="5" fill="#ff5f8f"/><circle cx="13" cy="18" r="5" fill="#ff5f8f"/><circle cx="13" cy="13" r="3.6" fill="#ffd23f"/>')],
        spray: [20, 40, g('<rect x="3" y="10" width="14" height="28" rx="3" fill="#60a5fa"/><rect x="6" y="4" width="8" height="6" fill="#fff"/><path d="M14 6h4" />')],
        szaglofelho: [60, 40, '<g class="sdh-sz-buz" fill="none" stroke="#4ade80" stroke-width="3" stroke-linecap="round"><path d="M8 38c-6-6 6-10 0-16s6-10 0-16"/><path d="M28 38c-6-6 6-10 0-16s6-10 0-16"/><path d="M48 38c-6-6 6-10 0-16s6-10 0-16"/></g>'],
        fingfelho: [44, 34, g('<path d="M8 30C0 30 0 20 8 18 8 8 20 6 24 12c4-6 16-4 14 6 6 2 6 12-2 12z" fill="rgba(134,239,172,.85)"/>')],
        kotel: [20, 140, g('<path d="M10 0v120" stroke="#a16207" stroke-width="3"/><circle cx="10" cy="126" r="7" fill="#ef4444"/>')],
        gomb: [40, 30, g('<rect x="2" y="16" width="36" height="12" rx="3" fill="#4b5563"/><path d="M8 16a12 9 0 0 1 24 0z" fill="#ef4444"/>')],
        pocsolya: [40, 10, '<ellipse cx="20" cy="5" rx="18" ry="4" fill="rgba(250,204,21,.55)"/>']
    };

    function svg(nev) {
        var r = RAJZ[nev];

        return '<svg viewBox="0 0 ' + r[0] + ' ' + r[1] + '" width="100%" height="100%" overflow="visible">' + r[2] + '</svg>';
    }

    // Kellékek a figurán (a saját rétegében, a figura terében: 0..100).
    var KELLEK = {
        sisak: '<path d="M14 30C14 6 86 6 86 30v6H14z" fill="#ef4444" stroke="#0b1020" stroke-width="3"/><path d="M20 28h60" stroke="#fff" stroke-width="3" opacity=".7"/>',
        ujsag: '<g transform="translate(2 62) rotate(-8)"><rect width="56" height="34" fill="#f8fafc" stroke="#0b1020" stroke-width="2.6"/><path d="M6 8h22M6 14h44M6 20h44M6 26h30" stroke="#64748b" stroke-width="2"/><text x="36" y="12" font-size="7" font-weight="900" fill="#0b1020">HÍR</text></g>',
        burger: '<g transform="translate(58 56) scale(.9)">' + RAJZ.burger[2] + '</g>',
        uditok: '<g transform="translate(66 50) scale(.9)">' + RAJZ.uditok[2] + '</g>',
        sulyzo: '<g transform="translate(-6 -6) scale(1.6 1.2)">' + RAJZ.sulyzo[2] + '</g>',
        telefon: '<g transform="translate(70 40)">' + RAJZ.telefon[2] + '</g>',
        virag: '<g transform="translate(-14 40) scale(1.1)">' + RAJZ.virag[2] + '</g>',
        spray: '<g transform="translate(-10 40)">' + RAJZ.spray[2] + '</g>',
        napszemuveg: '<path d="M18 40h64" stroke="#0b1020" stroke-width="3.4" stroke-linecap="round"/><path d="M22 40h22v8a11 11 0 0 1-22 0zM56 40h22v8a11 11 0 0 1-22 0z" fill="#0b1020"/>',
        fejhallgato: '<path d="M14 50C14 10 86 10 86 50" fill="none" stroke="#0b1020" stroke-width="5"/><rect x="6" y="42" width="14" height="22" rx="5" fill="#6366f1" stroke="#0b1020" stroke-width="2.6"/><rect x="80" y="42" width="14" height="22" rx="5" fill="#6366f1" stroke="#0b1020" stroke-width="2.6"/>',
        habok: '<g fill="rgba(255,255,255,.85)" stroke="#8fd3ff" stroke-width="1.6"><circle cx="20" cy="18" r="8"/><circle cx="34" cy="10" r="6"/><circle cx="78" cy="20" r="9"/><circle cx="64" cy="10" r="5"/><circle cx="12" cy="70" r="6"/><circle cx="90" cy="64" r="6"/></g>',
        kolomp: '<path d="M50 -8v8" stroke="#0b1020" stroke-width="2"/>'
    };

    function kellek(nev) {
        if (kellekHely) {
            kellekHely.innerHTML = nev && KELLEK[nev] ? KELLEK[nev] : '';
        }
    }

    /* ================================================================ */
    /* Jelenetmotor                                                       */
    /* ================================================================ */

    var fut = null;

    function meret() {
        return S.leny.offsetWidth || 66 * n;
    }

    /** translate: x > 0 balra, y > 0 felfelé (a felület nagyításával). */
    function T(x, y, tobb) {
        return 'translate(' + (-(x || 0) * n).toFixed(1) + 'px,' + (-(y || 0) * n).toFixed(1) + 'px)' + (tobb ? ' ' + tobb : '');
    }

    function animal(el, kepek, ms, opc) {
        if (!el || !el.animate) {
            return null;
        }

        var a = el.animate(kepek.map(function (k) { return typeof k === 'string' ? { transform: k } : k; }), Object.assign({ duration: ms, fill: 'forwards', easing: 'ease-in-out' }, opc || {}));

        if (fut) {
            fut.animok.push(a);
        }

        return a;
    }

    function jelenet(nev, fn) {
        if (fut || !S || S.nyugodt || !S.szabad()) {
            return false;
        }

        S.setalVege();
        fut = { nev: nev, idok: [], animok: [], elemek: [] };
        S.gyoker.classList.add('is-jelenet');
        S.gyoker.setAttribute('data-jelenet', nev);
        S.szemZar(60000);

        var c = {
            ido: function (ms, f) {
                var j = fut;

                j.idok.push(window.setTimeout(function () {
                    if (fut === j) {
                        f();
                    }
                }, ms));
            },
            /** Szereplő a színpadon: x = távolság a figura bal szélétől (balra), y = magasság az aljától. */
            szereplo: function (rajz, x, y, nagyit, osztaly) {
                var r = RAJZ[rajz];
                var el = document.createElement('div');
                var k = nagyit || 1;

                el.className = 'sdh-sz-szereplo sdh-sz-szereplo--' + rajz + (osztaly ? ' ' + osztaly : '');
                el.style.width = (r[0] * k * n).toFixed(1) + 'px';
                el.style.height = (r[1] * k * n).toFixed(1) + 'px';
                el.style.right = (meret() + (x || 0) * n).toFixed(1) + 'px';
                el.style.bottom = ((y || 0) * n).toFixed(1) + 'px';
                el.innerHTML = svg(rajz);
                szinpad.appendChild(el);
                fut.elemek.push(el);

                return el;
            },
            /** Rövid szöveg buborékban: a figura fölött, vagy egy szereplő fölött. */
            mond: function (szoveg, ms, kinek) {
                var b = document.createElement('div');

                b.className = 'sdh-sz-mond' + (kinek ? ' sdh-sz-mond--mas' : '');
                b.textContent = szoveg;

                if (kinek) {
                    b.style.right = (parseFloat(kinek.style.right) + parseFloat(kinek.style.width) / 2).toFixed(1) + 'px';
                    b.style.bottom = (parseFloat(kinek.style.bottom) + parseFloat(kinek.style.height) + 6 * n).toFixed(1) + 'px';
                }

                szinpad.appendChild(b);
                fut.elemek.push(b);
                window.setTimeout(function () { b.remove(); }, ms || 1800);

                return b;
            },
            figura: function (kepek, ms, opc) { return animal(S.leny, kepek, ms, opc); },
            mozgat: animal,
            mim: function (m, ms) { mimika(m, ms); },
            kellek: kellek,
            szikrak: function (db, f) { S.szikrak(db, f); },
            gesztus: function (nev, ms) { S.gesztus(nev, ms); },
            vege: function (ms) { c.ido(ms, jelenetVege); }
        };

        fn(c);

        return true;
    }

    function jelenetVege() {
        if (!fut) {
            return;
        }

        fut.idok.forEach(function (t) { window.clearTimeout(t); });
        fut.animok.forEach(function (a) { try { a.cancel(); } catch (x) { /* már kész */ } });
        fut.elemek.forEach(function (el) { el.remove(); });
        fut = null;

        if (S) {
            S.gyoker.classList.remove('is-jelenet');
            S.gyoker.removeAttribute('data-jelenet');
            S.szemZar(0);
        }

        kellek('');
        mimika(null);
    }

    /* ================================================================ */
    /* A jelenetek                                                        */
    /* ================================================================ */

    function veletlen(lista) {
        return lista[Math.floor(Math.random() * lista.length)];
    }

    var JELENETEK = {
        /* --- Hadakozás a csirkével --- */
        csirke: function (c) {
            var cs = c.szereplo('csirke', 260, 0, .9, 'is-jar');

            c.mim('gondolkodó', 1600);
            c.mozgat(cs, [T(0), T(-240)], 2200, { easing: 'linear' });
            c.ido(2200, function () {
                cs.classList.remove('is-jar');
                cs.classList.add('is-csipked');
                c.mim('döbbent');
                c.mond('Au! Hé!', 1300);
                c.figura([T(0), T(-6, 0, 'rotate(8deg)'), T(0)], 300, { iterations: 3 });
            });
            c.ido(3300, function () {
                c.mim('dühös');
                c.mond('Na megállj, te tollas!', 1500);
                c.kellek('');
                c.figura([T(0), T(30, 14, 'rotate(-12deg)'), T(0)], 500, { iterations: 2 });
                c.szikrak(6, 'csillag');
            });
            c.ido(4400, function () {
                var gyoz = Math.random() < .65;

                cs.classList.remove('is-csipked');

                if (gyoz) {
                    cs.classList.add('is-jar', 'is-fordul');
                    c.mozgat(cs, [T(-240), T(80, 20)], 1400, { easing: 'ease-in' });
                    c.mond('KOT-KOT-KODÁÁÁCS!', 1400, cs);
                    c.mim('büszke', 2200);
                    c.gesztus('ugral', 950);
                } else {
                    cs.classList.add('is-csipked');
                    c.mim('ijedt');
                    c.mond('Jó, jó, a tiéd a terület!', 1600);
                    c.figura([T(0), T(-40, 0), T(-40, 0, 'rotate(10deg)')], 900);
                }
            });
            c.vege(6200);
        },

        /* --- Egér --- */
        eger: function (c) {
            var e = c.szereplo('eger', 300, 0, .7, 'is-jar');

            c.mozgat(e, [T(0), T(-262)], 1800, { easing: 'linear' });
            c.ido(1500, function () {
                c.mim('rémült');
                c.mond('EGÉÉÉR!', 1300);
                c.figura([T(0), T(0, 60, 'scale(.92,1.08)'), T(0, 54)], 600);
            });
            c.ido(2100, function () {
                e.classList.remove('is-jar');
                e.classList.add('is-szimatol');
                c.mozgat(e, [T(-262), T(-262, 0, 'rotate(-6deg)'), T(-262)], 500, { iterations: 3 });
            });
            c.ido(3700, function () {
                c.mim('eltökélt');
                c.mond('Hess innen!', 1200);
                c.figura([T(0, 54), T(10, 0)], 400, { easing: 'ease-in' });
            });
            c.ido(4150, function () {
                e.classList.remove('is-szimatol');
                e.classList.add('is-jar', 'is-fordul');
                c.mozgat(e, [T(-262), T(260)], 900, { easing: 'ease-in' });
                c.figura([T(10, 0), T(90, 0), T(0)], 1600);
                c.mim('büszke', 2400);
                c.szikrak(5, 'csillag');
            });
            c.vege(6000);
        },

        /* --- A madár támad --- */
        madar: function (c) {
            var m = c.szereplo('madar', 320, 160, .8, 'is-repul');

            c.mim('fütyörésző', 1800);
            c.mozgat(m, [T(0), T(-300, -90, 'rotate(18deg)'), T(-320, -96), T(-200, 40, 'rotate(-10deg)')], 2600, { easing: 'ease-in' });
            c.ido(1700, function () {
                c.mim('kiütött');
                c.mond('AU! A fejem!', 1400);
                c.figura([T(0), T(0, 0, 'scale(1.15,.75)'), T(0)], 500);
                c.szikrak(6, 'csillag');
            });
            c.ido(3000, function () {
                c.mim('dühös');
                c.mond('Gyere csak vissza!', 1400);
            });
            c.mozgat(m, [T(-200, 40), T(-200, 40), T(-340, -90, 'rotate(20deg)'), T(400, 140, 'rotate(-20deg)')], 3600, { delay: 2600, easing: 'ease-in-out' });
            c.ido(4400, function () {
                c.mim('ijedt');
                c.figura([T(0), T(0, -22, 'scale(1.1,.6)'), T(0)], 900);
            });
            c.ido(5600, function () {
                c.mim('huncut', 1800);
                c.mond('Ha! Mellé!', 1400);
            });
            c.vege(7200);
        },

        /* --- Pechás Pityu: neki mindig rossz napja van --- */
        pityu: function (c) {
            var p = c.szereplo('pityu', 300, 0, .9, 'is-jar');
            var halal = veletlen(['ullo', 'villam', 'banan']);

            c.mozgat(p, [T(0), T(-170)], 2000, { easing: 'linear' });
            c.ido(1200, function () { c.mond('Szia, Szikra!', 1500, p); c.mim('boldog', 1600); });
            c.ido(2000, function () { p.classList.remove('is-jar'); });

            if (halal === 'ullo') {
                c.ido(2600, function () {
                    var gomb = c.szereplo('gomb', 20, 0, .7);

                    c.mim('értetlen');
                    c.mond('Ez a piros gomb mire való?', 1600);
                    c.mozgat(gomb, [T(0, 0, 'scale(.2)'), T(0, 0, 'scale(1)')], 300);
                });
                c.ido(4000, function () {
                    var u = c.szereplo('ullo', 182, 260, .9);

                    c.figura([T(0), T(0, -6, 'scale(1.05,.9)'), T(0)], 300);
                    c.mozgat(u, [T(0, 0), T(0, -232)], 450, { easing: 'cubic-bezier(.5,0,1,.5)' });
                });
                c.ido(4450, function () {
                    p.classList.add('is-lapos');
                    c.szikrak(6, 'csillag');
                });
            } else if (halal === 'villam') {
                c.ido(2600, function () {
                    c.mim('tüsszentő');
                    c.mond('Ha… haaa…', 1200);
                });
                c.ido(3800, function () {
                    var f = c.szereplo('felho', 160, 130, .9);
                    var v = c.szereplo('villam', 186, 52, .9, 'is-villan');

                    c.mond('HAPCI!', 1000);
                    c.figura([T(0), T(-8, 0, 'rotate(10deg)'), T(0)], 300);
                    c.mozgat(f, [{ opacity: 0 }, { opacity: 1 }], 200);
                    c.mozgat(v, [{ opacity: 0 }, { opacity: 1 }, { opacity: 0 }, { opacity: 1 }], 400);
                });
                c.ido(4300, function () { p.classList.add('is-egett'); });
            } else {
                c.ido(2600, function () {
                    c.kellek('burger');
                    c.mim('jóllakott', 1400);
                });
                c.ido(3600, function () {
                    var b = c.szereplo('banan', 120, 0, .9);

                    c.kellek('');
                    c.mozgat(b, [T(-30, 30, 'rotate(0)'), T(0, 0, 'rotate(360deg)')], 500);
                    c.mim('ártatlan');
                    c.mond('Hoppá, kicsúszott…', 1400);
                    p.classList.add('is-jar');
                    c.mozgat(p, [T(-170), T(-160)], 600, { easing: 'linear' });
                });
                c.ido(4300, function () {
                    p.classList.remove('is-jar');
                    c.mozgat(p, [T(-160), T(-140, 50, 'rotate(-120deg)'), T(-120, -120, 'rotate(-260deg)')], 900, { easing: 'ease-in' });
                });
            }

            c.ido(5200, function () {
                var sz = c.szereplo('szellem', 170, 10, .9);

                p.style.opacity = halal === 'banan' ? '0' : p.style.opacity;
                c.mozgat(sz, [{ transform: T(0, 0), opacity: 0 }, { transform: T(0, 30), opacity: 1, offset: .2 }, { transform: T(-10, 170, 'rotate(8deg)'), opacity: 0 }], 2600, { easing: 'ease-out' });
                c.mim('döbbent');
                c.mond('Jaj, már megint Pityu!', 1800);
            });
            c.ido(7000, function () {
                c.mim('ártatlan');
                c.mond('Fütty-fütty… én itt sem voltam.', 1800);
            });
            c.vege(8900);
        },

        /* --- A Ködbáró: villám a fénypenge ellen --- */
        kodbaro: function (c) {
            var b = c.szereplo('kodbaro', 330, 30, .95, 'is-lebeg');
            var gyoz = Math.random() < .7;

            c.mozgat(b, [T(0), T(-170)], 2000, { easing: 'ease-out' });
            c.ido(1300, function () {
                c.mond('Muhahaha! A te kis szikrád ma kialszik!', 2000, b);
                c.mim('gyanakvó');
            });
            c.ido(3300, function () {
                S.kellek('penge', 6000);
                c.mim('eltökélt');
                c.mond('Azt majd meglátjuk, Báró!', 1600);
                S.allapot('buszke', 2400);
            });
            c.ido(4400, function () {
                var v = c.szereplo('villam', 120, 20, .7, 'is-villan');

                c.mozgat(v, [{ opacity: 0 }, { opacity: 1 }, { opacity: 0 }, { opacity: 1 }, { opacity: 0 }], 900);
                c.figura([T(0), T(14, 4, 'rotate(-14deg)'), T(0), T(14, 4, 'rotate(-14deg)'), T(0)], 900);
                c.szikrak(8, 'csillag');
            });
            c.ido(5500, function () {
                if (gyoz) {
                    b.classList.add('is-elparolog');
                    c.mozgat(b, [{ transform: T(-170), opacity: 1 }, { transform: T(-170, 30, 'scale(.2)'), opacity: 0 }], 900);
                    c.mond('Neeee… a ködöööm…', 1200, b);
                    c.mim('sztár', 2000);
                    c.gesztus('ugral', 950);
                } else {
                    c.mim('kiütött');
                    S.kellek('');
                    c.figura([T(0), T(-20, 0, 'rotate(20deg)'), T(0)], 700);
                    c.mond('Muhaha! Majd legközelebb!', 1500, b);
                    c.mozgat(b, [T(-170), T(260, 40)], 1500, { delay: 900, easing: 'ease-in' });
                }
            });
            c.ido(7400, function () { S.kellek(''); });
            c.vege(7800);
        },

        /* --- Pisilés a bokorba --- */
        pisi: function (c) {
            var bokor = c.szereplo('bokor', 120, 0, .9);

            c.mim('erőlködő', 1400);
            c.mond('Bocsi, mindjárt jövök…', 1400);
            c.figura([T(0), T(80, 0)], 1300);
            c.ido(1400, function () {
                c.figura([T(80, 0), T(80, 0, 'scaleX(-1)')], 200);
                c.mim('megkönnyebbült');
                c.mond('Psssssss…', 2600);
            });
            c.ido(1800, function () {
                var p = c.szereplo('pocsolya', 140, 0, .9);

                c.mozgat(p, [{ transform: 'scale(.1)', opacity: 0 }, { transform: 'scale(1)', opacity: 1 }], 2200);
                c.mozgat(bokor, [T(0), T(0, 0, 'rotate(2deg)'), T(0)], 400, { iterations: 5 });
            });
            c.ido(4300, function () {
                c.figura([T(80, 0, 'scaleX(-1)'), T(80, 0, 'scaleX(-1) rotate(6deg)'), T(80, 0, 'scaleX(-1)')], 200, { iterations: 2 });
                c.mim('vigyorgó');
            });
            c.ido(4900, function () {
                c.figura([T(80, 0), T(0)], 1200);
                c.mond('Áhh, sokkal jobb.', 1400);
            });
            c.vege(6400);
        },

        /* --- Kakilás újsággal --- */
        kaki: function (c) {
            var wc = c.szereplo('wc', 10, 0, 1);

            c.mozgat(wc, [{ transform: 'scale(.2)', opacity: 0 }, { transform: 'scale(1)', opacity: 1 }], 350);
            c.mim('erőlködő', 1000);
            c.mond('Ez most nem várhat!', 1200);
            c.ido(800, function () {
                c.figura([T(0), T(18, 36)], 500);
            });
            c.ido(1400, function () {
                c.kellek('ujsag');
                c.mim('unott');
            });
            c.ido(2800, function () {
                c.mim('erőlködő');
                c.mond('Nnnngh…', 1500);
                c.figura([T(18, 36), T(18, 34, 'scale(1.06,.94)'), T(18, 36)], 400, { iterations: 3 });
            });
            c.ido(4400, function () {
                c.mond('*plutty*', 1000);
                c.mim('megkönnyebbült');
            });
            c.ido(5400, function () {
                var buz = c.szereplo('szaglofelho', 4, 50, .8);

                c.mim('büdös', 1800);
                c.mozgat(buz, [{ opacity: 0 }, { opacity: 1 }], 400);
                c.kellek('');
                c.mond('Hú. Ez erős volt.', 1500);
            });
            c.ido(7000, function () {
                c.figura([T(18, 36), T(0)], 500);
                c.kellek('spray');
                c.mond('Pssszt – levendula.', 1500);
                c.mim('ártatlan');
            });
            c.vege(8800);
        },

        /* --- Fing --- */
        fing: function (c) {
            var f = c.szereplo('fingfelho', -30, 4, .9);

            c.mozgat(f, [{ transform: T(0, 0, 'scale(.2)'), opacity: 0 }, { transform: T(-6, 10, 'scale(1)'), opacity: 1, offset: .3 }, { transform: T(-20, 40, 'scale(1.4)'), opacity: 0 }], 3200);
            c.mond('Prrrrt.', 1200);
            c.figura([T(0), T(0, 6, 'scale(.96,1.04)'), T(0)], 300);
            c.mim('döbbent', 900);
            c.ido(1000, function () { c.mim('ártatlan', 2200); c.mond('Nem én voltam. A nyomtató volt.', 2000); });
            c.vege(3600);
        },

        /* --- Büdös: aztán zuhany --- */
        budos: function (c) {
            var buz = c.szereplo('szaglofelho', -10, 46, .8);

            c.mim('büdös');
            c.mond('Szimat… szimat… ez én vagyok?', 1800);
            c.figura([T(0), T(0, 0, 'rotate(-12deg)'), T(0)], 700);
            c.ido(2000, function () {
                c.mim('undorodó');
                c.mond('Fúj! Zuhany, MOST!', 1400);
            });
            c.ido(3200, function () {
                var z = c.szereplo('zuhany', -40, 10, 1);

                buz.remove();
                c.mozgat(z, [{ opacity: 0 }, { opacity: 1 }], 300);
                c.kellek('habok');
                c.mim('fütyörésző');
                c.mond('Lalalaaa…', 2400);
                c.figura([T(0), T(-4, 0, 'rotate(-5deg)'), T(4, 0, 'rotate(5deg)'), T(0)], 800, { iterations: 3 });
            });
            c.ido(6000, function () {
                c.kellek('');
                c.mim('csillogó szemű', 1800);
                c.szikrak(9, 'csillag');
                c.mond('Illatos vagyok!', 1600);
            });
            c.vege(7600);
        },

        /* --- Tüsszentés --- */
        tusszent: function (c) {
            c.mim('tüsszentő');
            c.mond('Ha… haaa…', 1000);
            c.figura([T(0), T(-6, 4, 'rotate(-8deg)')], 900);
            c.ido(1000, function () { c.mond('HAAAA…', 700); c.figura([T(-6, 4, 'rotate(-8deg)'), T(-10, 8, 'rotate(-14deg)')], 600); });
            c.ido(1700, function () {
                c.mond('HAPCI!', 1300);
                c.figura([T(-10, 8, 'rotate(-14deg)'), T(24, 0, 'rotate(12deg) scale(1.08,.92)'), T(0)], 500, { easing: 'cubic-bezier(.2,1.6,.4,1)' });
                c.szikrak(8, 'csillag');
                c.mim('kiütött', 900);
            });
            c.ido(2800, function () { c.mim('szégyenlős', 1500); c.mond('Bocsi. Egészségemre.', 1400); });
            c.vege(4400);
        },

        /* --- Csuklás --- */
        csuklik: function (c) {
            c.mim('meglepett');
            [0, 900, 1700, 2600].forEach(function (t) {
                c.ido(t, function () {
                    c.mond('hukk!', 600);
                    c.figura([T(0), T(0, 10, 'scale(.95,1.05)'), T(0)], 260);
                });
            });
            c.ido(3400, function () { c.mim('morcos', 1600); c.mond('Valaki ijesszen meg!', 1400); });
            c.vege(5000);
        },

        /* --- Evés, böfi --- */
        eves: function (c) {
            c.kellek('burger');
            c.mim('éhes');
            c.mond('Nyami!', 1000);
            [700, 1300, 1900, 2500].forEach(function (t, i) {
                c.ido(t, function () {
                    c.mim(i % 2 ? 'jóllakott' : 'vigyorgó');
                    c.figura([T(0), T(0, -3, 'scale(1.04,.96)'), T(0)], 260);
                });
            });
            c.ido(3200, function () { c.kellek(''); c.mim('jóllakott'); });
            c.ido(4000, function () {
                c.mond('BÖFF!', 1100);
                c.figura([T(0), T(0, 6, 'scale(1.1,.9)'), T(0)], 400);
                c.mim('döbbent', 600);
            });
            c.ido(4800, function () { c.mim('szégyenlős', 1400); c.mond('Pardon.', 1200); });
            c.vege(6300);
        },

        ivas: function (c) {
            c.kellek('uditok');
            c.mim('boldog');
            c.mond('Glugy-glugy-glugy…', 1800);
            c.figura([T(0), T(0, 0, 'rotate(-10deg)')], 600);
            c.ido(2200, function () { c.figura([T(0, 0, 'rotate(-10deg)'), T(0)], 400); c.kellek(''); c.mim('megkönnyebbült', 1400); c.mond('Ahh!', 900); });
            c.vege(3800);
        },

        /* --- Csajozás --- */
        csajozas: function (c) {
            var cs = c.szereplo('csilla', 320, 0, .85, 'is-jar');

            c.mozgat(cs, [T(0), T(-170)], 2400, { easing: 'linear' });
            c.ido(1200, function () { c.mim('szerelmes'); c.szikrak(5, 'sziv'); });
            c.ido(2400, function () {
                cs.classList.remove('is-jar');
                c.kellek('napszemuveg');
                c.mim('laza');
                c.mond('Szia, szépség! Gyakran jársz erre a képernyőre?', 2200);
                c.gesztus('tancol', 1800);
            });
            c.ido(4600, function () {
                c.kellek('virag');
                c.mim('puszis');
                c.mond('Ezt neked hoztam.', 1400);
            });
            c.ido(6000, function () {
                if (Math.random() < .7) {
                    c.mond('Jaj, de édes vagy!', 1600, cs);
                    c.mim('szerelmes', 2000);
                    c.szikrak(9, 'sziv');
                    c.mozgat(cs, [T(-170), T(-150, 10), T(-170)], 500);
                } else {
                    c.mond('Pff. Nem vagy az esetem.', 1600, cs);
                    c.kellek('');
                    c.figura([T(0), T(-14, 0, 'rotate(18deg)'), T(0)], 400);
                    c.mim('kiütött', 1000);
                    c.ido(1100, function () { c.mim('pityergő', 1400); c.mond('Pedig megfürödtem…', 1400); });
                }
            });
            c.ido(7800, function () {
                c.kellek('');
                cs.classList.add('is-jar', 'is-fordul');
                c.mozgat(cs, [T(-170), T(320)], 2000, { easing: 'linear' });
            });
            c.vege(9800);
        },

        /* --- A barátnő meglátogatja --- */
        baratno: function (c) {
            var cs = c.szereplo('csilla', 320, 0, .85, 'is-jar');

            c.mozgat(cs, [T(0), T(-230)], 2000, { easing: 'ease-out' });
            c.ido(800, function () { c.mim('csillogó szemű'); c.mond('Csilla!', 1200); c.gesztus('ugral', 950); });
            c.ido(2000, function () {
                cs.classList.remove('is-jar');
                c.mond('Szia, Szikrám! Hiányoztál!', 1700, cs);
                c.figura([T(0), T(28, 0)], 500);
                c.mozgat(cs, [T(-230), T(-250, 0, 'rotate(-6deg)')], 500);
            });
            c.ido(2700, function () {
                c.mim('puszis');
                c.szikrak(12, 'sziv');
                c.mond('*cupp*', 1200);
            });
            c.ido(4200, function () {
                c.mim('szerelmes');
                c.mond('Mit csinálunk ma este?', 1600);
            });
            c.ido(5900, function () {
                c.mond('Moziba megyünk! De előbb dolgozz szépen.', 2000, cs);
            });
            c.ido(8000, function () {
                c.figura([T(28, 0), T(0)], 600);
                cs.classList.add('is-jar', 'is-fordul');
                c.mozgat(cs, [T(-250), T(320)], 2000, { easing: 'ease-in' });
                c.gesztus('integet', 1700);
                c.mim('boldog', 2000);
            });
            c.vege(10200);
        },

        /* --- Motorozás --- */
        motor: function (c) {
            var m = c.szereplo('motor', -20, 0, 1);
            var tav = Math.min(900, window.innerWidth * .8) / n;

            c.mozgat(m, [{ opacity: 0 }, { opacity: 1 }], 300);
            c.kellek('sisak');
            c.mim('eltökélt');
            c.mond('Brrrumm-brrrumm!', 1400);
            c.figura([T(0), T(-44, 26)], 500);
            c.ido(1500, function () {
                m.classList.add('is-megy');
                c.figura([T(-44, 26), T(tav - 44, 26)], 2200, { easing: 'ease-in' });
                c.mozgat(m, [T(0), T(tav)], 2200, { easing: 'ease-in' });
            });
            c.ido(3900, function () {
                c.figura([T(tav - 44, 26, 'scaleX(-1)'), T(-44, 30, 'scaleX(-1) rotate(-14deg)'), T(-44, 26, 'scaleX(-1)')], 2400, { easing: 'ease-out' });
                c.mozgat(m, [T(tav, 0, 'scaleX(-1)'), T(0, 6, 'scaleX(-1) rotate(-14deg)'), T(0, 0, 'scaleX(-1)')], 2400, { easing: 'ease-out' });
                c.mim('sztár');
            });
            c.ido(6400, function () {
                m.classList.remove('is-megy');
                c.mond('Egy kerékenzés, hogy lássák!', 1600);
                c.figura([T(-44, 26), T(0)], 500);
                c.kellek('');
            });
            c.vege(7800);
        },

        /* --- Autózás --- */
        auto: function (c) {
            var a = c.szereplo('auto', -40, 0, 1);
            var tav = Math.min(900, window.innerWidth * .8) / n;

            c.mozgat(a, [{ opacity: 0 }, { opacity: 1 }], 300);
            c.mim('boldog');
            c.figura([T(0), T(-36, 14, 'scale(.62)')], 500);
            c.ido(800, function () { c.mond('Tütü!', 1000); });
            c.ido(1600, function () {
                a.classList.add('is-megy');
                c.figura([T(-36, 14, 'scale(.62)'), T(tav - 36, 14, 'scale(.62)')], 2600, { easing: 'ease-in-out' });
                c.mozgat(a, [T(0), T(tav)], 2600, { easing: 'ease-in-out' });
            });
            c.ido(4300, function () {
                c.mim(Math.random() < .5 ? 'vigyorgó' : 'ijedt');
                c.figura([T(tav - 36, 14, 'scale(.62)'), T(-36, 14, 'scale(.62)')], 2400, { easing: 'ease-in-out' });
                c.mozgat(a, [T(tav), T(0)], 2400, { easing: 'ease-in-out' });
            });
            c.ido(6800, function () {
                a.classList.remove('is-megy');
                c.mond('Parkolás: tökéletes. Majdnem.', 1600);
                c.figura([T(-36, 14, 'scale(.62)'), T(0)], 500);
            });
            c.vege(8300);
        },

        /* --- Edzés --- */
        edzes: function (c) {
            c.kellek('sulyzo');
            c.mim('erőlködő');
            c.mond('Egy… kettő… hááárom…', 2400);
            c.figura([T(0), T(0, -4, 'scale(1.05,.92)'), T(0)], 600, { iterations: 4 });
            c.ido(2600, function () { c.mim('erőlködő, izzadva'); });
            c.ido(3000, function () { c.kellek(''); c.mim('büszke', 1800); c.mond('Nézd ezt a bicepszet! (Nincs bicepszem.)', 2000); c.gesztus('tancol', 1600); });
            c.vege(5200);
        },

        /* --- Telefonál a barátnőjével --- */
        telefon: function (c) {
            c.kellek('telefon');
            c.mim('szerelmes');
            c.mond('Halló, Csilla? …Nem, te tedd le! …Nem, te!', 2600);
            c.ido(2700, function () { c.szikrak(6, 'sziv'); c.mim('puszis', 1600); c.mond('Puszi-puszi!', 1200); });
            c.vege(4400);
        },

        /* --- Zenét hallgat --- */
        zene: function (c) {
            c.kellek('fejhallgato');
            c.mim('fütyörésző');
            c.gesztus('tancol', 2600);
            c.figura([T(0), T(0, 0, 'rotate(-6deg)'), T(0, 0, 'rotate(6deg)'), T(0)], 700, { iterations: 3 });
            c.ido(2200, function () { c.mond('Tsz-tsz-tsz…', 1400); });
            c.vege(4200);
        },

        /* --- Vakarózik --- */
        vakarozik: function (c) {
            c.mim('gyanakvó');
            c.mond('Valami csíp…', 1200);
            c.figura([T(0), T(-3, 0, 'rotate(-4deg)'), T(3, 0, 'rotate(4deg)'), T(0)], 220, { iterations: 6 });
            c.ido(1500, function () { c.mim('megkönnyebbült', 1400); c.mond('Megvan!', 900); });
            c.vege(3000);
        }
    };

    // Melyik jelenet melyik szükségletet csillapítja.
    var IGENY_JELENET = {
        ehseg: ['eves', 'ivas'],
        pisi: ['pisi'],
        kaki: ['kaki'],
        budos: ['budos'],
        magany: ['baratno', 'csajozas', 'telefon'],
        kaland: ['motor', 'auto', 'kodbaro', 'edzes', 'csirke', 'eger', 'madar', 'pityu']
    };

    var ALTESTI_JELENETEK = ['pisi', 'kaki', 'fing', 'budos'];

    // Alkalmi jelenetek súlya (a szükségletektől függetlenül is előfordulnak).
    var ALKALMI = [
        ['csirke', 3], ['eger', 3], ['madar', 3], ['pityu', 3], ['kodbaro', 2], ['tusszent', 3], ['csuklik', 2],
        ['csajozas', 2], ['baratno', 1], ['motor', 2], ['auto', 2], ['edzes', 2], ['telefon', 2], ['zene', 2],
        ['vakarozik', 2], ['fing', 2], ['ivas', 1]
    ].filter(function (j) { return ALTESTI || ALTESTI_JELENETEK.indexOf(j[0]) === -1; });

    /* ================================================================ */
    /* Szükségletek                                                       */
    /* ================================================================ */

    // Percenkénti növekedés (100-nál „sürgős").
    var NOVEKEDES = { ehseg: 1.1, pisi: 1.5, kaki: .6, faradt: .8, budos: .45, magany: .7, kaland: 1 };
    var IGENY_NEV = { ehseg: 'éhes', pisi: 'pisilnie kell', kaki: 'WC-re kell mennie', faradt: 'fáradt', budos: 'büdös', magany: 'hiányzik neki Csilla', kaland: 'kalandra vágyik' };
    var igeny = {};
    var TAR = 'sdh-szikra-igeny';

    function igenyBetolt() {
        var most = Date.now();
        var adat = null;

        try {
            adat = JSON.parse(window.localStorage.getItem(TAR) || 'null');
        } catch (x) {
            adat = null;
        }

        Object.keys(NOVEKEDES).forEach(function (k) {
            igeny[k] = adat && adat.v && typeof adat.v[k] === 'number' ? adat.v[k] : Math.random() * 50;
        });

        if (adat && adat.t) {
            igenyNo(Math.min(120, Math.max(0, (most - adat.t) / 60000)));
        }

        if (!ALTESTI) {
            igeny.pisi = igeny.kaki = igeny.budos = 0;
        }
    }

    function igenyMent() {
        try {
            window.localStorage.setItem(TAR, JSON.stringify({ t: Date.now(), v: igeny }));
        } catch (x) { /* privát ablak: csak ebben a munkamenetben él */ }
    }

    function igenyNo(perc) {
        Object.keys(NOVEKEDES).forEach(function (k) {
            igeny[k] = Math.min(130, (igeny[k] || 0) + NOVEKEDES[k] * perc);
        });

        if (!ALTESTI) {
            igeny.pisi = igeny.kaki = igeny.budos = 0;
        }
    }

    function sorgos() {
        return Object.keys(igeny).filter(function (k) { return igeny[k] >= 100 && (IGENY_JELENET[k] || k === 'faradt'); })
            .sort(function (a, b) { return igeny[b] - igeny[a]; });
    }

    /** A szükséglet kielégítése egy jelenettel (vagy ásítással, ha fáradt). */
    function igenyKielegit(k) {
        if (k === 'faradt') {
            if (S.szabad()) {
                mimika('álmos', 2600);
                S.gesztus('nyujt', 1700);
                igeny.faradt = 10;
            }

            return;
        }

        var nev = veletlen(IGENY_JELENET[k]);

        if (inditJelenet(nev)) {
            igeny[k] = Math.random() * 15;

            // Evés után pisilni és WC-re is kell.
            if (k === 'ehseg' && ALTESTI) {
                igeny.pisi = Math.min(130, igeny.pisi + 25);
                igeny.kaki = Math.min(130, igeny.kaki + 15);
            }

            igenyMent();
        }
    }

    function cim() {
        var s = sorgos().concat(Object.keys(igeny).filter(function (k) { return igeny[k] >= 75 && igeny[k] < 100; }));
        var alap = S.nev + ' – kérdezz bátran! (F1)';

        S.leny.title = s.length ? alap + ' · ' + s.slice(0, 3).map(function (k) { return IGENY_NEV[k]; }).join(', ') : alap;
    }

    /* ================================================================ */
    /* Indulás                                                            */
    /* ================================================================ */

    function veletlenJelenet() {
        var ossz = ALKALMI.reduce(function (o, j) { return o + j[1]; }, 0);
        var r = Math.random() * ossz;
        var i;

        for (i = 0; i < ALKALMI.length; i++) {
            r -= ALKALMI[i][1];

            if (r <= 0) {
                return ALKALMI[i][0];
            }
        }

        return ALKALMI[0][0];
    }

    function inditJelenet(nev) {
        return jelenet(nev, JELENETEK[nev]);
    }

    function nyugton() {
        return Date.now() - utolsoAktiv > 8000;
    }

    function indit() {
        S = window.SDH_SZIKRA;

        if (!S || szinpad) {
            return;
        }

        n = parseFloat(window.getComputedStyle(document.documentElement).getPropertyValue('--sdh-n')) || 1;

        szinpad = document.createElement('div');
        szinpad.className = 'sdh-sz-szinpad';
        szinpad.setAttribute('aria-hidden', 'true');
        S.gyoker.appendChild(szinpad);

        arcSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        arcSvg.setAttribute('viewBox', '0 0 100 100');
        arcSvg.setAttribute('class', 'sdh-sz-arc');
        arcSvg.setAttribute('aria-hidden', 'true');
        arcSvg.setAttribute('overflow', 'visible');
        S.leny.appendChild(arcSvg);

        kellekHely = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        kellekHely.setAttribute('viewBox', '0 0 100 100');
        kellekHely.setAttribute('class', 'sdh-sz-kellek');
        kellekHely.setAttribute('aria-hidden', 'true');
        kellekHely.setAttribute('overflow', 'visible');
        S.leny.appendChild(kellekHely);

        igenyBetolt();
        cim();

        // Munka közben nem lábatlankodik: kattintás, gépelés azonnal leállítja.
        ['keydown', 'click', 'pointerdown'].forEach(function (es) {
            document.addEventListener(es, function () {
                utolsoAktiv = Date.now();
                jelenetVege();
            }, { passive: true, capture: true });
        });
        document.addEventListener('sdh:level-toast', jelenetVege);
        document.addEventListener('sdh:mentve', jelenetVege);

        // Magától: grimaszol (a leggyakoribb), és néha egy jelenet.
        S.cselekves(6, function () {
            if (Math.random() < .3) {
                var m1 = veletlenMimika();
                var m2 = veletlenMimika();

                mimika(m1, 1300);
                window.setTimeout(function () { if (S.szabad()) { mimika(m2, 1500); } }, 1350);
            } else {
                mimika(veletlenMimika(), 1600 + Math.random() * 1400);
            }
        });

        if (!S.nyugodt) {
            S.cselekves(5, function () {
                if (nyugton()) {
                    inditJelenet(veletlenJelenet());
                }
            });
        }

        // Percenként nőnek a szükségletek; ha valami sürgős, és épp ráér, intézi.
        window.setInterval(function () {
            igenyNo(.5);
            igenyMent();
            cim();

            var s = sorgos();

            if (s.length && !S.nyugodt && nyugton() && S.szabad() && S.elenk > 0) {
                igenyKielegit(s[0]);
            }
        }, 30000);
    }

    // Más modulok és a tesztek is elérik (pl. kézzel indított jelenet).
    window.SDH_SZIKRA_ELET_API = {
        mimikak: MIMIKAK,
        mimika: mimika,
        jelenetek: Object.keys(JELENETEK),
        jatszik: function (nev) { utolsoAktiv = 0; return JELENETEK[nev] ? inditJelenet(nev) : false; },
        leallit: jelenetVege,
        igeny: function () { return Object.assign({}, igeny); }
    };

    if (window.SDH_SZIKRA) {
        indit();
    } else {
        document.addEventListener('sdh:szikra-kesz', indit);
    }
}());
