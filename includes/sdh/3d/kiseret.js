/**
 * SDH – belső: 3D kísérő (0.66.0). CSAK AZ SDH SZERVIZÉ (includes/sdh/).
 *
 * Saját feltöltésű .glb modell Szikra mellett, valódi 3D-ben (three.js, WebGL, GPU):
 *   - finoman lebeg és „lélegzik", alatta puha árnyék, ami a magassággal együtt halványul;
 *   - a tekintetével követi az egeret (simított, rugós mozgás), tétlenségben Szikrára néz;
 *   - kattintásra megpördül, Szikra ugrik és szikrázik;
 *   - követi Szikra helyét (ha sétál vagy a felület nagyít), a mozgatás compositor-réteg;
 *   - rejtett lapon nem rajzol; szoftveres rajzolásnál (nincs hardveres gyorsítás) és
 *     „kevesebb mozgás" beállításnál mozdulatlan, egyetlen kép.
 */
import * as THREE from './vendor/three.module.min.js';
import { GLTFLoader } from './vendor/GLTFLoader.js';
import { RoomEnvironment } from './vendor/RoomEnvironment.js';

const cimke = document.getElementById('sdh-3d-kiseret');
let B = {};

try {
    B = JSON.parse(cimke ? cimke.getAttribute('data-beall') || '{}' : '{}');
} catch (x) {
    B = {};
}

function n() {
    return parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--sdh-n')) || 1;
}

function szikraElem() {
    return document.querySelector('.sdh-ai:not(.sdh-ai-figura__kep) .sdh-ai__leny');
}

function indit() {
    if (!B.url || window.SDH_3D_KISERO) {
        return;
    }

    window.SDH_3D_KISERO = true;

    const csendes = document.documentElement.classList.contains('sdh-gyenge-gep')
        || (window.SDH_GPU && window.SDH_GPU.allapot !== 'gyors')
        || window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const doboz = document.createElement('div');
    doboz.className = 'sdh-3d';
    doboz.title = [B.nev, B.kredit].filter(Boolean).join(' – ') || '3D kísérő';
    doboz.setAttribute('aria-hidden', 'true');
    document.body.appendChild(doboz);

    let renderer;

    try {
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
    } catch (x) {
        doboz.remove();

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

    const kamera = new THREE.PerspectiveCamera(24, 1, 0.01, 100);
    const forgo = new THREE.Group();   // egér-követés, pördülés
    const lebego = new THREE.Group();  // lebegés
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

    let meret = 0;
    let alj = -0.5;

    function meretez() {
        const m = Math.round((B.meret || 130) * n());

        if (m !== meret) {
            meret = m;
            doboz.style.width = doboz.style.height = m + 'px';
            renderer.setSize(m, m, false);
        }
    }

    // Hely: Szikra mellé; a mozgatás transform (GPU-réteg), CSS-átmenettel.
    let utolsoHely = '';

    function helyez() {
        const sz = szikraElem();

        if (!sz) {
            doboz.style.opacity = '0';

            return;
        }

        const r = sz.getBoundingClientRect();
        const res = 8 * n();
        let x;
        let y;

        if (B.hely === 'felette') {
            x = r.left + r.width / 2 - meret / 2;
            y = r.top - meret - res;
        } else {
            x = r.left - meret - res;
            y = r.bottom - meret;
        }

        x = Math.max(4, Math.min(window.innerWidth - meret - 4, Math.round(x)));
        y = Math.max(4, Math.min(window.innerHeight - meret - 4, Math.round(y)));
        const h = x + ',' + y;

        if (h !== utolsoHely) {
            utolsoHely = h;
            doboz.style.transform = 'translate3d(' + x + 'px,' + y + 'px,0)';
        }

        doboz.style.opacity = '';
    }

    // Egér: a tekintet célja (-1..1), simítva.
    const cel = { x: 0, y: 0 };
    const most = { x: 0, y: 0 };
    let utolsoEger = performance.now();

    if (B.eger !== false && !csendes) {
        window.addEventListener('pointermove', function (e) {
            const r = doboz.getBoundingClientRect();
            const kx = r.left + r.width / 2;
            const ky = r.top + r.height / 2;

            cel.x = Math.max(-1, Math.min(1, (e.clientX - kx) / (window.innerWidth * 0.45)));
            cel.y = Math.max(-1, Math.min(1, (e.clientY - ky) / (window.innerHeight * 0.6)));
            utolsoEger = performance.now();
        }, { passive: true });
    }

    // Kattintás: pördülés + Szikra reagál.
    let porges = -1;
    let hover = 0;

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

    doboz.addEventListener('pointerenter', function () { hover = 1; });
    doboz.addEventListener('pointerleave', function () { hover = 0; });

    const sima = (a, b, k) => a + (b - a) * k;
    const kezdo = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

    let ido = 0;
    let elozo = performance.now();
    let skala = 1;

    function rajzol(dt) {
        ido += dt;

        // Tétlenségben (8 mp egér nélkül) Szikra felé néz, aztán vissza.
        let cx = cel.x;
        let cy = cel.y;

        if (B.eger === false || performance.now() - utolsoEger > 8000) {
            const fazis = Math.sin(ido * 0.35);
            cx = B.hely === 'felette' ? fazis * 0.5 : 0.35 + fazis * 0.35;
            cy = B.hely === 'felette' ? 0.5 : 0.1;
        }

        const k = 1 - Math.exp(-dt * 4.5);
        most.x = sima(most.x, cx, k);
        most.y = sima(most.y, cy, k);

        let porgo = 0;

        if (porges >= 0) {
            porges += dt / 1.4;
            porgo = kezdo(Math.min(1, porges)) * Math.PI * 2;

            if (porges >= 1) {
                porges = -1;
                porgo = 0;
            }
        }

        const alap = (B.forgas || 0) * Math.PI / 180;

        forgo.rotation.y = alap + most.x * 0.65 + porgo;
        forgo.rotation.x = most.y * 0.28;
        forgo.rotation.z = -most.x * 0.06;

        const emel = csendes ? 0 : Math.sin(ido * 1.9) * 0.035 + (porges >= 0 ? Math.sin(Math.min(1, porges) * Math.PI) * 0.12 : 0);
        lebego.position.y = emel;
        skala = sima(skala, 1 + hover * 0.06 + (csendes ? 0 : Math.sin(ido * 1.3) * 0.012), 1 - Math.exp(-dt * 8));
        lebego.scale.setScalar(skala);

        arnyek.position.y = alj - 0.02;
        const tav = Math.max(0, emel + 0.05);
        arnyek.scale.setScalar(0.85 + tav * 1.5);
        arnyek.material.opacity = Math.max(0.25, 0.9 - tav * 4);

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

    meretez();
    helyez();
    window.addEventListener('resize', function () { meretez(); helyez(); if (csendes) { rajzol(0); } });
    window.setInterval(helyez, 250);

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

        // Előbb a shaderek fordulnak le és kész képkocka áll elő – csak utána tűnik fel,
        // így soha nem villan fekete vagy félkész kép.
        renderer.compile(scene, kamera);
        rajzol(0);
        start();
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { doboz.classList.add('is-kesz'); });
        });
    }, undefined, function () {
        doboz.remove();
    });
}

function kesobb() {
    // A felület után, ráérő időben: a munka nem vár a 3D-re.
    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(indit, { timeout: 2500 });
    } else {
        window.setTimeout(indit, 800);
    }
}

if (document.readyState === 'complete') {
    kesobb();
} else {
    window.addEventListener('load', kesobb);
}
