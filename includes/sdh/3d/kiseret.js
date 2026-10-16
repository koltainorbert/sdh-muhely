/**
 * SDH – belső: 3D figura (0.66.1; 0.66.2: kéz, láb, séta, párbaj; 0.68.0: több modell). CSAK AZ SDH SZERVIZÉ (includes/sdh/).
 *
 * Saját feltöltésű .glb modell valódi 3D-ben (three.js, WebGL, GPU). Két szerep:
 *
 *   „helyette" – a modell MAGA AZ ASSZISZTENS: Szikra testének helyén él, és mindent
 *                ugyanúgy csinál, mint Szikra – kattintásra nyílik a csevegő, buborékban
 *                beszél, sétál, végigvezet, jeleneteket játszik, alszik. Szikra hangulatait
 *                (data-allapot) és gesztusait (is-g-*) 3D mozdulatokra fordítja: gondolkodik
 *                → billeg és lüktet a fénye, beszél → bólogat, örül → ugrik, szomorú →
 *                lehajtja a fejét, alszik → elbóbiskol, pörög → megpördül, táncol → ring…
 *   „mellette" – kísérő Szikra mellett (külön kattintható, kattintásra megpördül).
 *
 * Mindkettő: lebeg, a tekintetével követi az egeret, rejtett lapon nem rajzol, szoftveres
 * rajzolásnál és „kevesebb mozgás" mellett állókép (állapotváltáskor újrarajzolva).
 */
import * as THREE from './vendor/three.module.min.js';
import { GLTFLoader } from './vendor/GLTFLoader.js';
import { RoomEnvironment } from './vendor/RoomEnvironment.js';

const cimke = document.getElementById('sdh-3d-kiseret');
let ADAT = {};

try {
    ADAT = JSON.parse(cimke ? cimke.getAttribute('data-beall') || '{}' : '{}');
} catch (x) {
    ADAT = {};
}

// 0.68.0: több modell – egy Szikra helyett és egy mellette egyszerre is futhat.
const LISTA = Array.isArray(ADAT.figurak) ? ADAT.figurak : (ADAT.url ? [ADAT] : []);

function nagyitas() {
    return parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sdh-n')) || 1;
}

function szikraLeny() {
    return document.querySelector('.sdh-ai:not(.sdh-ai-figura__kep) .sdh-ai__leny');
}

/* ===================================================================== */
/* Szikra hangulatai és gesztusai → 3D póz                                  */
/* ===================================================================== */

// Alappóz hangulatonként: elfordulás (yaw), bólintás (pitch, + = előre), dőlés (roll),
// emelés (y), méret, fényerő, és mennyire kövesse az egeret.
const POZ = {
    nyugi: {},
    figyel: { pitch: 0.12, scale: 1.03, fenyes: 1.15 },
    kivancsi: { pitch: 0.1, roll: 0.14, scale: 1.04 },
    gondolkodik: { pitch: -0.12, roll: 0.1, eger: 0.3 },
    beszel: { pitch: 0.06, fenyes: 1.2 },
    orul: { y: 0.05, scale: 1.06, fenyes: 1.35 },
    nevet: { pitch: -0.18, scale: 1.05, fenyes: 1.3 },
    buszke: { pitch: -0.2, y: 0.03, scale: 1.05 },
    szomoru: { pitch: 0.38, y: -0.06, scale: 0.96, fenyes: 0.6, eger: 0.2 },
    jelez: { roll: -0.12, fenyes: 1.4 },
    mutat: { yaw: -0.45, pitch: 0.1, eger: 0.2 },
    alszik: { pitch: 0.42, roll: 0.16, y: -0.07, fenyes: 0.35, eger: 0 },
    asit: { pitch: -0.35, scale: 1.04 },
    kacsint: { roll: 0.22 },
    meglepett: { pitch: -0.15, y: 0.06, scale: 1.1, fenyes: 1.4 },
    ijedt: { pitch: -0.1, y: -0.03, scale: 0.92 },
    szedul: { roll: 0.12 },
    kave: { pitch: 0.18 },
    olvas: { pitch: 0.3, eger: 0.1 }
};

function hangulatPoz(nev) {
    const p = POZ[nev] || {};

    return {
        yaw: p.yaw || 0,
        pitch: p.pitch || 0,
        roll: p.roll || 0,
        y: p.y || 0,
        scale: p.scale || 1,
        fenyes: p.fenyes || 1,
        eger: p.eger === undefined ? 1 : p.eger
    };
}

/** Folyamatos rátét a hangulatra (t: másodperc). */
function hangulatMozgas(nev, t) {
    const o = { yaw: 0, pitch: 0, roll: 0, y: 0, scale: 0, fenyes: 0 };

    switch (nev) {
        case 'gondolkodik':
            o.roll = Math.sin(t * 1.6) * 0.12;
            o.yaw = Math.sin(t * 0.8) * 0.18;
            o.fenyes = 0.35 + Math.sin(t * 5) * 0.35;      // lüktető fény
            break;
        case 'beszel':
            o.pitch = Math.abs(Math.sin(t * 7)) * 0.1;      // bólogat beszéd közben
            o.fenyes = Math.abs(Math.sin(t * 9)) * 0.3;
            break;
        case 'nevet':
            o.roll = Math.sin(t * 18) * 0.06;
            o.y = Math.abs(Math.sin(t * 9)) * 0.03;
            break;
        case 'orul':
            o.y = Math.abs(Math.sin(t * 6)) * 0.05;
            break;
        case 'alszik':
            o.pitch = Math.sin(t * 0.9) * 0.05;             // lassú szuszogás
            o.scale = Math.sin(t * 0.9) * 0.015;
            break;
        case 'ijedt':
            o.yaw = Math.sin(t * 40) * 0.025;               // remeg
            break;
        case 'szedul':
            o.roll = Math.sin(t * 3) * 0.22;
            o.pitch = Math.cos(t * 3) * 0.15;
            break;
        case 'jelez':
            o.fenyes = Math.max(0, Math.sin(t * 8)) * 0.8;  // villog
            break;
        case 'kivancsi':
            o.roll = Math.sin(t * 1.2) * 0.05;
            break;
        default:
            break;
    }

    return o;
}

const kozep = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

/** Gesztusok (is-g-<név>): f = eltelt idő mp-ben a gesztus kezdete óta. */
function gesztusMozgas(nev, f) {
    const o = { yaw: 0, pitch: 0, roll: 0, y: 0, scale: 0 };

    switch (nev) {
        case 'porog':
            o.yaw = kozep(Math.min(1, f / 0.9)) * Math.PI * 2;
            break;
        case 'ugral':
            o.y = Math.max(0, Math.sin(Math.min(1, f / 0.5) * Math.PI)) * 0.14;
            o.scale = o.y * 0.4;
            break;
        case 'integet':
            o.roll = Math.sin(f * 14) * 0.18 * Math.max(0, 1 - f / 1.6);
            break;
        case 'bolint':
            o.pitch = Math.sin(Math.min(1, f / 0.5) * Math.PI) * 0.35;
            break;
        case 'tancol':
            o.roll = Math.sin(f * 6.5) * 0.25;
            o.yaw = Math.sin(f * 3.25) * 0.6;
            o.y = Math.abs(Math.sin(f * 6.5)) * 0.06;
            break;
        case 'razkodik':
            o.yaw = Math.sin(f * 45) * 0.12 * Math.max(0, 1 - f / 0.8);
            break;
        case 'nyujt':
            o.scale = Math.sin(Math.min(1, f / 1.2) * Math.PI) * 0.12;
            o.pitch = -Math.sin(Math.min(1, f / 1.2) * Math.PI) * 0.3;
            break;
        case 'tiszteleg':
            // Pénz a kasszában (0.67.0): bokacsattintásnál kis szökkenés, aztán kihúzza magát,
            // állát felszegi, és mereven, egyenesen előre néz (az egeret ilyenkor nem követi).
            o.y = f < 0.45 ? Math.sin(f / 0.45 * Math.PI) * 0.05 : 0;
            o.pitch = f < 0.45 ? 0 : -0.12 * Math.min(1, (f - 0.45) / 0.2) * Math.max(0, Math.min(1, (2.8 - f) / 0.3));
            o.scale = f > 0.45 && f < 2.5 ? 0.03 : 0;
            break;
        case 'kukucs':
            o.roll = Math.sin(Math.min(1, f / 0.9) * Math.PI) * 0.4;
            o.yaw = Math.sin(Math.min(1, f / 0.9) * Math.PI) * 0.5;
            break;
        default:
            break;
    }

    return o;
}

/**
 * Szikra „állapot-osztályai" (0.66.2): séta (is-setal) és párbaj a hiba-bogárral
 * (is-parbaj, is-parbaj-viv, is-parbaj-lemerul). A bogár balról jön.
 */
function osztalyMozgas(lista, t) {
    const o = { yaw: 0, pitch: 0, roll: 0, y: 0, scale: 0, eger: 1 };

    if (lista.contains('is-setal')) {
        o.y = Math.abs(Math.sin(t * 14)) * 0.035;          // lépésenként kicsit felbillen
        o.roll = Math.sin(t * 14) * 0.09;                  // totyog
        o.pitch = 0.08;
    }

    if (lista.contains('is-parbaj')) {
        o.yaw = -0.75;                                     // a bogár felé fordul
        o.eger = 0;

        if (lista.contains('is-parbaj-viv')) {
            const c = Math.sin(t * 15);
            o.yaw += c * 0.28;                             // csapások jobbra-balra
            o.roll = -c * 0.16;
            o.pitch = 0.12 + Math.abs(c) * 0.08;           // rádől a csapásra
            o.y = Math.abs(Math.cos(t * 15)) * 0.02;
        }

        if (lista.contains('is-parbaj-lemerul')) {
            o.pitch = 0.3;                                 // lemerült a penge: lekonyul
            o.y = -0.04;
        }
    }

    return o;
}

/* ===================================================================== */
/* Indulás                                                                 */
/* ===================================================================== */

function indit(B) {
    const HELYETTE = B.mod !== 'mellette';
    const ZAR = HELYETTE ? 'SDH_3D_BENNE' : 'SDH_3D_MELLETTE';

    if (!B.url || window[ZAR]) {
        return;
    }

    const leny = szikraLeny();

    if (HELYETTE && !leny) {
        // Szikra még nem készült el: ha szól, hogy kész, indulunk.
        document.addEventListener('sdh:szikra-kesz', function () { indit(B); }, { once: true });

        return;
    }

    window[ZAR] = true;

    const gyoker = leny ? leny.closest('.sdh-ai') : null;
    const csendes = document.documentElement.classList.contains('sdh-gyenge-gep')
        || (window.SDH_GPU && window.SDH_GPU.allapot !== 'gyors')
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const doboz = document.createElement('div');
    doboz.className = HELYETTE ? 'sdh-3d sdh-3d--benne' : 'sdh-3d sdh-3d--mellette';
    doboz.setAttribute('aria-hidden', 'true');

    if (B.kredit) {
        doboz.setAttribute('data-kredit', B.kredit);
    }

    if (HELYETTE) {
        leny.appendChild(doboz);
    } else {
        doboz.title = [B.nev, B.kredit].filter(Boolean).join(' – ') || '3D kísérő';
        document.body.appendChild(doboz);
    }

    let renderer;

    try {
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
    } catch (x) {
        doboz.remove();
        window[ZAR] = false;

        return;
    }

    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.15;
    renderer.setClearColor(0x000000, 0);
    doboz.appendChild(renderer.domElement);

    const scene = new THREE.Scene();

    // Tükröződő környezet (fényes fém felületekhez) csak valódi GPU-n: a szoftveres
    // rajzolók egy része a PMREM lebegőpontos textúráit nem tudja – ott fekete lenne.
    const gpu = !!(window.SDH_GPU && window.SDH_GPU.allapot === 'gyors');

    if (gpu) {
        const pmrem = new THREE.PMREMGenerator(renderer);
        scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
        pmrem.dispose();
    }

    // Fények: fő fény elölről-felülről, és két „neon" élfény (kék, lila) a CRM tech-hangulatához.
    const fo = new THREE.DirectionalLight(0xffffff, 2.2);
    fo.position.set(2, 3, 4);
    const kek = new THREE.DirectionalLight(0x38bdf8, 3.2);
    kek.position.set(-3, 1.5, -2.5);
    const lila = new THREE.DirectionalLight(0xa855f7, 2.4);
    lila.position.set(3, 1, -3);
    scene.add(fo, kek, lila, new THREE.HemisphereLight(0xdbeafe, 0x0b1020, gpu ? 0.35 : 1.6));

    if (!gpu) {
        // Környezet nélkül erősebb szemből jövő fény, hogy a sötét, fémes felület is látsszon.
        const szem = new THREE.PointLight(0xffffff, 6, 0, 1.4);
        szem.position.set(0.4, 0.6, 2.2);
        scene.add(szem);
    }

    const KEK = 3.2;
    const LILA = 2.4;

    const kamera = new THREE.PerspectiveCamera(24, 1, 0.01, 100);
    const forgo = new THREE.Group();   // tekintet, hangulat, gesztus
    const lebego = new THREE.Group();  // lebegés, méret
    lebego.add(forgo);
    scene.add(lebego);

    // Puha árnyék: kör alakú átmenet egy síkon a modell alatt.
    const v = document.createElement('canvas');
    v.width = v.height = 128;
    const g = v.getContext('2d');
    const gr = g.createRadialGradient(64, 64, 0, 64, 64, 64);
    gr.addColorStop(0, 'rgba(0,0,0,.55)');
    gr.addColorStop(1, 'rgba(0,0,0,0)');
    g.fillStyle = gr;
    g.fillRect(0, 0, 128, 128);
    const arnyek = new THREE.Mesh(
        new THREE.PlaneGeometry(1, 1),
        new THREE.MeshBasicMaterial({ map: new THREE.CanvasTexture(v), transparent: true, depthWrite: false })
    );
    arnyek.rotation.x = -Math.PI / 2;
    scene.add(arnyek);

    let szel = 0;
    let mag = 0;
    let alj = -0.5;

    function meretez() {
        let w;
        let h;

        if (HELYETTE) {
            const r = doboz.getBoundingClientRect();
            w = Math.round(r.width);
            h = Math.round(r.height);
        } else {
            w = h = Math.round((B.meret || 130) * nagyitas());
            doboz.style.width = doboz.style.height = w + 'px';
        }

        if (w > 0 && h > 0 && (w !== szel || h !== mag)) {
            szel = w;
            mag = h;
            renderer.setSize(w, h, false);
            kamera.aspect = w / h;
            kamera.updateProjectionMatrix();

            return true;
        }

        return false;
    }

    // „Mellette" szerep: Szikra mellé helyezés (transform, CSS-átmenettel).
    let utolsoHely = '';

    function helyez() {
        if (HELYETTE) {
            if (meretez() && csendes) {
                rajzol(0);
            }

            return;
        }

        const sz = szikraLeny();

        if (!sz) {
            doboz.style.opacity = '0';

            return;
        }

        const r = sz.getBoundingClientRect();
        const res = 8 * nagyitas();
        let x;
        let y;

        if (B.hely === 'felette') {
            x = r.left + r.width / 2 - szel / 2;
            y = r.top - mag - res;
        } else {
            x = r.left - szel - res;
            y = r.bottom - mag;
        }

        x = Math.max(4, Math.min(window.innerWidth - szel - 4, Math.round(x)));
        y = Math.max(4, Math.min(window.innerHeight - mag - 4, Math.round(y)));
        const h = x + ',' + y;

        if (h !== utolsoHely) {
            utolsoHely = h;
            doboz.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0)';
        }

        doboz.style.opacity = '';
    }

    // Egér: a tekintet célja (-1..1).
    const cel = { x: 0, y: 0 };
    let utolsoEger = performance.now();

    if (B.eger !== false && !csendes) {
        window.addEventListener('pointermove', function (e) {
            const r = (HELYETTE ? leny : doboz).getBoundingClientRect();
            const kx = r.left + r.width / 2;
            const ky = r.top + r.height / 2;

            cel.x = Math.max(-1, Math.min(1, (e.clientX - kx) / (window.innerWidth * 0.45)));
            cel.y = Math.max(-1, Math.min(1, (e.clientY - ky) / (window.innerHeight * 0.6)));
            utolsoEger = performance.now();
        }, { passive: true });
    }

    // Kattintás: „helyette" szerepben a leny kattintása Szikráé (csevegő); „mellette" pördül.
    let porges = -1;
    let hover = 0;

    if (!HELYETTE) {
        doboz.addEventListener('click', function () {
            if (porges >= 0) {
                return;
            }

            porges = 0;

            const S = window.SDH_SZIKRA;

            try {
                if (S && S.gesztus) { S.gesztus('ugral', 950); }
                if (S && S.szikrak) { S.szikrak(6, 'csillag'); }
            } catch (x) { /* a figura épp foglalt */ }

            if (csendes) {
                rajzol(0);
            }
        });
    }

    const hoverElem = HELYETTE ? leny : doboz;
    hoverElem.addEventListener('pointerenter', function () { hover = 1; });
    hoverElem.addEventListener('pointerleave', function () { hover = 0; });

    // Szikra állapotának olvasása (hangulat, gesztus) – „helyette" szerepben.
    let gesztusNev = '';
    let gesztusKezd = 0;

    function gesztusOlvas(most) {
        if (!gyoker) {
            return '';
        }

        let nev = '';

        gyoker.classList.forEach(function (o) {
            if (o.indexOf('is-g-') === 0) {
                nev = o.slice(5);
            }
        });

        if (nev !== gesztusNev) {
            gesztusNev = nev;
            gesztusKezd = most;
        }

        return nev;
    }

    const sima = (a, b, k) => a + (b - a) * k;

    // A simított póz.
    const poz = { yaw: 0, pitch: 0, roll: 0, y: 0, scale: 1, fenyes: 1, ex: 0, ey: 0 };
    let ido = 0;
    let elozo = performance.now();

    function rajzol(dt) {
        ido += dt;

        const most = performance.now();
        const hang = HELYETTE && gyoker ? (gyoker.getAttribute('data-allapot') || 'nyugi') : 'nyugi';
        const alap = hangulatPoz(hang);
        const mozg = csendes ? { yaw: 0, pitch: 0, roll: 0, y: 0, scale: 0, fenyes: 0 } : hangulatMozgas(hang, ido);
        const gnev = HELYETTE ? gesztusOlvas(most) : '';
        const gm = gnev && !csendes ? gesztusMozgas(gnev, (most - gesztusKezd) / 1000) : { yaw: 0, pitch: 0, roll: 0, y: 0, scale: 0 };
        const om = HELYETTE && gyoker ? osztalyMozgas(gyoker.classList, ido) : { yaw: 0, pitch: 0, roll: 0, y: 0, scale: 0, eger: 1 };

        if (csendes) {
            om.y = 0;
        }

        // Tekintet: egér, vagy tétlenségben magától nézelődik / Szikra felé néz.
        let cx = cel.x;
        let cy = cel.y;

        if (B.eger === false || most - utolsoEger > 8000) {
            const f = Math.sin(ido * 0.35);
            cx = HELYETTE ? f * 0.45 : (B.hely === 'felette' ? f * 0.5 : 0.35 + f * 0.35);
            cy = HELYETTE ? 0.15 : (B.hely === 'felette' ? 0.5 : 0.1);
        }

        const k = dt > 0 ? 1 - Math.exp(-dt * 4.5) : 1;
        const k2 = dt > 0 ? 1 - Math.exp(-dt * 3) : 1;

        const egyenes = gnev === 'tiszteleg' ? 0 : 1;   // tisztelgésnél mereven előre néz

        poz.ex = sima(poz.ex, cx * alap.eger * om.eger * egyenes, k);
        poz.ey = sima(poz.ey, cy * alap.eger * om.eger * egyenes, k);
        poz.yaw = sima(poz.yaw, alap.yaw + (om.eger === 0 ? om.yaw : 0), k2);
        poz.pitch = sima(poz.pitch, alap.pitch, k2);
        poz.roll = sima(poz.roll, alap.roll, k2);
        poz.y = sima(poz.y, alap.y, k2);
        poz.scale = sima(poz.scale, alap.scale + hover * 0.05, k2);
        poz.fenyes = sima(poz.fenyes, alap.fenyes, k2);

        let porgo = 0;

        if (porges >= 0) {
            porges += dt / 1.4;
            porgo = kozep(Math.min(1, porges)) * Math.PI * 2;

            if (porges >= 1) {
                porges = -1;
                porgo = 0;
            }
        }

        const irany = (B.forgas || 0) * Math.PI / 180;

        forgo.rotation.order = 'YXZ';
        // A párbaj alapfordulása (om.yaw) simítva a poz.yaw-ban van; a csapás-rezgés közvetlenül.
        const csapas = om.eger === 0 ? 0 : om.yaw;

        forgo.rotation.y = irany + poz.ex * 0.65 + poz.yaw + mozg.yaw + gm.yaw + csapas + porgo
            + (gyoker && gyoker.classList.contains('is-parbaj-viv') ? Math.sin(ido * 15) * 0.28 : 0);
        forgo.rotation.x = poz.ey * 0.28 + poz.pitch + mozg.pitch + gm.pitch + om.pitch;
        forgo.rotation.z = -poz.ex * 0.06 + poz.roll + mozg.roll + gm.roll + om.roll;

        const lebeg = csendes ? 0 : Math.sin(ido * 1.9) * 0.035;
        const emel = lebeg + poz.y + mozg.y + gm.y + om.y + (porges >= 0 ? Math.sin(Math.min(1, porges) * Math.PI) * 0.12 : 0);

        lebego.position.y = emel;
        lebego.scale.setScalar(poz.scale + mozg.scale + gm.scale + (csendes ? 0 : Math.sin(ido * 1.3) * 0.012));

        const f = Math.max(0.2, poz.fenyes + mozg.fenyes);
        kek.intensity = KEK * f;
        lila.intensity = LILA * f;

        arnyek.position.y = alj - 0.02;
        const tav = Math.max(0, emel + 0.05);
        arnyek.scale.setScalar(0.85 + tav * 1.5);
        arnyek.material.opacity = Math.max(0.2, 0.9 - tav * 4);

        renderer.render(scene, kamera);
    }

    let fut = false;

    function ciklus(t) {
        if (!fut) {
            return;
        }

        const dt = Math.max(0, Math.min(0.05, (t - elozo) / 1000));
        elozo = t;
        rajzol(dt);
        requestAnimationFrame(ciklus);
    }

    function start() {
        if (fut || csendes || document.hidden) {
            return;
        }

        fut = true;
        elozo = performance.now();
        requestAnimationFrame(ciklus);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            fut = false;
        } else {
            start();
        }
    });

    window.addEventListener('resize', function () { meretez(); helyez(); if (csendes) { rajzol(0); } });
    window.setInterval(helyez, 250);

    // Állókép módban az állapotváltásra újrarajzol (hangulat, gesztus).
    if (csendes && gyoker) {
        new MutationObserver(function () { rajzol(0); }).observe(gyoker, { attributes: true, attributeFilter: ['data-allapot', 'class'] });
    }

    new GLTFLoader().load(B.url, function (gltf) {
        const modell = gltf.scene;
        const doboz3 = new THREE.Box3().setFromObject(modell);
        const m = doboz3.getSize(new THREE.Vector3());
        const kp = doboz3.getCenter(new THREE.Vector3());
        const max = Math.max(m.x, m.y, m.z) || 1;

        modell.position.sub(kp);
        modell.traverse(function (o) {
            if (o.isMesh && o.material) {
                (Array.isArray(o.material) ? o.material : [o.material]).forEach(function (mat) {
                    if ('envMapIntensity' in mat) {
                        mat.envMapIntensity = 1.25;
                    }

                    // Tiszta fém (metalness 1) környezet nélkül fekete foltnak látszik; kicsit
                    // „lakkosabbra" véve a fények is rajzolják, a csillogás megmarad.
                    if ('metalness' in mat) {
                        mat.metalness = Math.min(mat.metalness, gpu ? 0.75 : 0.45);
                        mat.roughness = Math.max(mat.roughness, 0.28);
                    }
                });
            }
        });

        forgo.add(modell);
        forgo.scale.setScalar(1 / max);
        alj = -(m.y / max) / 2;

        // Kitöltés: a modell nagyobbik (normált) kiterjedése férjen ki kis ráhagyással;
        // a pördülésnél a mélység is számít, ezért a vízszintes átló is benne van.
        const sz = Math.max(m.y / max, Math.hypot(m.x, m.z) / max);
        const tav = (sz / 2) / Math.tan(THREE.MathUtils.degToRad(kamera.fov / 2)) * 1.12 + 0.5;

        kamera.position.set(0, 0.08, tav);
        kamera.lookAt(0, -0.02, 0);

        meretez();
        helyez();

        // Előbb a shaderek fordulnak le és kész képkocka áll elő – csak utána tűnik fel
        // (és „helyette" szerepben csak ekkor tűnik el Szikra teste), így soha nem villan
        // fekete vagy üres kép.
        renderer.compile(scene, kamera);
        rajzol(0);
        start();
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                doboz.classList.add('is-kesz');

                if (HELYETTE && gyoker) {
                    gyoker.classList.add('is-3d');
                    // Saját keze-lába van (egész alakos modell): a rajzolt kesztyű-csizma nem kell.
                    gyoker.classList.toggle('is-3d-testes', !!B.vegtag);
                }
            });
        });
    }, undefined, function () {
        // Nem tölthető be a modell: Szikra marad.
        doboz.remove();
        window[ZAR] = false;
    });
}

function kesobb() {
    // A felület után, ráérő időben: a munka nem vár a 3D-re.
    const mind = function () { LISTA.forEach(indit); };

    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(mind, { timeout: 2500 });
    } else {
        window.setTimeout(mind, 800);
    }
}

if (document.readyState === 'complete') {
    kesobb();
} else {
    window.addEventListener('load', kesobb);
}
