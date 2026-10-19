# Arculat – értékesítő webhely (`sdh-sales-tema`)

Tervező ügynök, 2026-10-19. A tokenek forrása: `tema/sdh-sales-tema/assets/css/tokenek.css`.
A bővítmény (`SDH_Sales_Arculat::css()`) a szerződés szerinti `--s-…` változókat felülírhatja; minden más token
ezekből vagy saját alapértékből dolgozik. **Nyers színérték csak a tokenfájlban állhat.**

## 1. Irány
Sötét alapú, high-tech „műhely éjjel” hangulat: mélyfekete-kék háttér, áttetsző üvegpanelek (glassmorphism),
visszafogott neonfény három színben. A világos mód ugyanaz a rendszer nappali fényben: tiszta, hűvös szürke,
fehér kártyák, a neon helyett színes árnyék. Kerek, „pill” formák mindenhol – a CRM-ben megszokott lekerekítés
felerősítve. Olvashatóság mindenek előtt: a díszítés (ragyogás, aurora, rács) soha nem kerül szöveg mögé.

## 2. Paletta és a CRM-mel való kapcsolat
| Szerep | Token | Sötét | Világos | Honnan |
|---|---|---|---|---|
| Háttér | `--s-bg` | `#07080c` | `#f4f5f9` | Központ 2.0 `--kp-hatter` (sötét), hasonló világos |
| Felület (kártya) | `--s-felulet` | `#11131b` | `#ffffff` | Központ panel |
| Üveg | `--s-uveg` | `rgba(22,25,36,.66)` | `rgba(255,255,255,.72)` | Központ `--kp-panel` |
| Szöveg | `--s-szoveg` | `#eef0f6` | `#0f1222` | Központ `--kp-szoveg` |
| Halvány szöveg | `--s-halvany` | `#a3a9bb` | `#4b5168` | Központ `--kp-szoveg-2` |
| Vonal | `--s-vonal` | `rgba(255,255,255,.10)` | `rgba(15,18,34,.10)` | dekor (nem hordoz jelentést) |
| Kiemelő (márka) | `--s-kiemelo` | `#ff5a52` | `#c41f1a` | **SDH-piros** – Műhely `--sdh-accent #d4231d` (sötéten világosítva, a Műhely `--sdh-accent-szoveg #f0736d` mintájára) |
| Kiemelő 2 | `--s-kiemelo-2` | `#9d8fff` | `#5b47d6` | Központ-ibolya `#7c6cff` |
| Neon | `--s-neon` | `#22d3ee` | `#0e7490` | Központ `--kp-kiemelo-2` |
| Siker / figyelem / hiba | `--s-ok` `--s-figyel` `--s-hiba` | `#34d399` `#fbbf24` `#fb7185` | `#047857` `#a14a06` `#be123c` | Központ állapotszínek (világoson sötétítve az AA-hoz) |

Kiegészítő tokenek: `--s-bg-2` (váltakozó szekció), `--s-felulet-2`, `--s-uveg-tomor` (tartalék backdrop-filter
nélkül), `--s-uveg-keret`, `--s-uveg-feny`, `--s-vonal-eros`, `--s-mezo`, `--s-mezo-keret`, `--s-*-felirat` (szöveg
telt színen), `--s-*-halvany` (címke-/pill-háttér), `--s-atmenet` (korall → ibolya → cián), `--s-atmenet-szoveg`,
`--s-aurora-1..3`, `--s-racs-vonal`, `--s-fokusz`, `--s-arnyek-emelt`, `--s-arnyek-kicsi`,
`--s-ragyogas-kiemelo|kiemelo-2|neon|szoveg`.

A három márkaszín aránya: kiemelő (CTA, ár, legfontosabb művelet) ~10%, ibolya + cián (díszítés, ikon, diagram,
fókusz) ~10%, a többi semleges. **Egy képernyőn egy elsődleges (korall) gomb.**

## 3. Kontraszt (WCAG 2.2 AA) – mérve
Mérés: `kontraszt.py` (a tokenfájlból olvas, az áttetsző rétegeket a háttérre keveri, WCAG relatív luminancia).
A szkript azt is ellenőrzi, hogy a `prefers-color-scheme: light` blokk egyezik a `[data-tema="vilagos"]` blokkal.
Felületek: `bg`, `bg-2`, `felulet`, `felulet-2`, üveg `bg`-n és `bg-2`-n, `uveg-tomor`, mező a felületen, és minden
színnek a saját `-halvany` címkehátterén.

| Szöveg-token | Sötét min. | Világos min. |
|---|---|---|
| `--s-szoveg` | 14,46 | 16,03 |
| `--s-halvany` | 7,02 | 6,77 |
| `--s-kiemelo` | 5,12 | 5,09 |
| `--s-kiemelo-2` | 5,39 | 5,46 |
| `--s-neon` | 8,24 | **4,62** |
| `--s-ok` | 7,48 | 4,73 |
| `--s-figyel` | 8,40 | 5,18 |
| `--s-hiba` | 5,67 | 5,39 |
| Felirat telt gombon (kiemelő / kiemelő-2 / neon) | 6,40 / 7,31 / 10,88 | 5,90 / 6,33 / 5,36 |
| **Legkisebb szövegkontraszt** | **5,12** | **4,62** |

UI-elemek (WCAG 1.4.11, ≥ 3:1): mezőkeret `--s-mezo-keret` sötét 3,75 / 3,83, világos 3,66 / 3,74;
fókuszgyűrű `--s-fokusz` sötét 13,15, világos 6,12. A `--s-vonal` és `--s-vonal-eros` csak díszítő elválasztó
(1,5–1,9:1) – állapotot, mezőhatárt **nem** jelölhet.

Szabályok: az átmenetes szöveg (`--s-atmenet-szoveg`) csak ≥ 24 px-es címben; a neon ragyogás alatt a szöveg
színe a fenti tokenek egyike (a ragyogás nem számít a kontrasztba). Képre írt szövegnél a kép alá
`--s-bg` 75–94%-os átmenetes sötétítés kerül (a hero bal harmada már eleve sötét).

Akadálymentes médialekérdezések a tokenfájlban: `prefers-reduced-motion` (minden időtartam ≈ 0, nincs elmozdulás),
`prefers-reduced-transparency` (az üveg tömör lesz, blur 0), `prefers-contrast: more` (halvány = fő szöveg, erős
vonal, tömör üveg), `forced-colors` (árnyék, ragyogás, blur ki).

## 4. Tipográfia
| Szerep | Betű | Csomag (önkiszolgálva) | Miért |
|---|---|---|---|
| Cím (`--s-betu-cim`) | **Bricolage Grotesque** (variable, opsz + wght) | `@fontsource-variable/bricolage-grotesque` | Karakteres, kissé „műhelyes” groteszk, optikai méret-tengellyel: nagyban feszes, kicsiben nyitott. Nem a megszokott Inter/Space Grotesk. |
| Szöveg (`--s-betu-szoveg`) | **Onest** (variable, wght) | `@fontsource-variable/onest` | Képernyőre rajzolt, nyitott formájú, nagy x-magasságú – kis méretben is jól olvasható; semleges a karakteres cím mellett. |
| Kód, licenckulcs, IMEI (`--s-betu-kod`) | **JetBrains Mono** (variable) | `@fontsource-variable/jetbrains-mono` | Egyértelmű 0/O, 1/l/I; a Központ már ezt használja. |

Magyar ékezetek: mindhárom családnak van `latin-ext` alkészlete (ő, ű, Ő, Ű). A front ügynök a `latin` + `latin-ext`
woff2-t töltse be, `font-display: swap`, a címbetűt `preload`-dal. **Ellenőrizendő:** az npm-regiszter ebből a
környezetből nem volt elérhető (DNS-hiba), így a csomagok verzióját és a `latin-ext` fájl meglétét a telepítéskor
kell megnézni; ha bármelyik hiányzik, tartalék a tokenben: rendszerbetű.

Skála (`--s-m-…`, folyékony `clamp()`, 360 → 1440 px): `xs` 13 · `s` 14 · `alap` 16 · `l` 17–19 · `xl` 20–24 ·
`2xl` 24–32 · `3xl` 30–44 · `4xl` 38–64 · `5xl` 44–88 (hero). Sorköz: `--s-sorkoz-szoros` 1,08 (hero, h1),
`--s-sorkoz-cim` 1,18, `--s-sorkoz` 1,6. Címek: `--s-suly-cim` 720, `--s-betukoz-cim` −0,02em. Nagybetűs
címke (eyebrow): `--s-m-xs`, `--s-betukoz-felirat` 0,08em, `--s-neon` szín. Sorhossz: `--s-sor-max` 68ch.
Számok (ár, statisztika): `font-variant-numeric: tabular-nums`.

## 5. Térköz, rács, rétegek
- Térköz `--s-t-0..10` (4 px alap: 4, 8, 12, 16, 24, 32, 48, 64, 96, 128), szekció `--s-t-szekcio` (64–128),
  oldalmargó `--s-t-oldal` (16–40; mobilon 16 px), kapcsoló körüli levegő `--s-t-kapcsolo` = 6 px.
- Tartalomszélesség `--s-max` 1240 px, szöveges oldal `--s-max-szuk` 760 px, teljes sáv `--s-max-szeles` 1440 px.
- Z-index: `--s-z-alap` 0 · `emelt` 10 · `ragados` 100 · `fejlec` 200 · `legordulo` 300 · `fiok` 400 · `hatter` 500 ·
  `modal` 510 · `kereso` 600 · `szikra` 700 · `suti` 800 · `ertesites` 900 · `tooltip` 1000.

## 6. Komponensek
**Gomb** – magasság `--s-magassag-gomb` 48 px (kicsi 36 px, de érintési cél ≥ 44 px padding/pseudo-elemmel),
`--s-r-gomb` (pill), vízszintes padding `--s-t-5`.
- Elsődleges: háttér `--s-kiemelo`, szöveg `--s-kiemelo-felirat`, hover: `--s-ragyogas-kiemelo`, `translateY(-1px)`.
- Másodlagos: üveg (`--s-uveg` + `backdrop-filter: blur(var(--s-uveg-blur)) saturate(var(--s-uveg-szaturacio))`),
  keret `--s-uveg-keret`, hover: keret `--s-kiemelo-2`.
- Szellem: átlátszó, szöveg `--s-szoveg`, hover háttér `--s-kiemelo-2-halvany`.
- Kétlépcsős (`data-ketlepcsos`): 2. állapotban háttér `--s-hiba-halvany`, keret és szöveg `--s-hiba`.
- Fókusz (minden interaktív elemen): `outline: var(--s-fokusz-vastag) solid var(--s-fokusz); outline-offset: var(--s-fokusz-tav)`.

**Pill / címke** – `--s-r-pill`, magasság 28–32 px, `--s-m-s`, háttér `--s-<szín>-halvany`, szöveg `--s-<szín>`,
opcionális 6 px-es pont elöl. Állapot soha nem csak színnel: mindig van szöveg vagy ikon is.

**Kártya** – `--s-felulet`, `--s-r-kartya` (24 px), keret `1px solid var(--s-vonal)`, `--s-arnyek`; padding `--s-t-6`.
Kiemelt (pl. ajánlott árcsomag): keret `--s-atmenet` (border-image vagy maszkolt pseudo-elem) + `--s-ragyogas-kiemelo-2`.

**Üvegpanel** – háttér `--s-uveg`, `backdrop-filter` (fejlécen, popupon `--s-uveg-blur-eros`), keret `--s-uveg-keret`,
felső fény `--s-uveg-feny` rétegként. `@supports not (backdrop-filter: blur(1px))` → `--s-uveg-tomor`.
Csak tartalom fölött lebegő elemre (fejléc, popup, kereső, Szikra, kiemelt kártya) – nem minden kártyára.

**Kapcsoló** (`.s-kapcsolo`) – 44×24 px, sín `--s-r-pill`, kikapcsolva sín `--s-vonal-eros` + keret `--s-mezo-keret`,
bekapcsolva `--s-kiemelo-2`; gomb fehér, mozgás `--s-ido` `--s-gorbe-rugo`. **Felirat nélkül** (nincs Aktív/Inaktív),
körülötte ≥ `--s-t-kapcsolo` (6 px) szabad hely. A címke a kapcsoló mellett a beállítás neve, `<label>`-lel kötve.

**Beviteli mező / legördülő** – magasság 48 px, `--s-r-mezo` (pill), háttér `--s-mezo`, keret `1px solid var(--s-mezo-keret)`,
fókusz: keret `--s-kiemelo-2` + fókuszgyűrű. Többsoros: `--s-r-tobbsoros` (15 px). Hiba: keret `--s-hiba`,
alatta üzenet ikonnal. Natív dátummező tilos – `sdh-datum` komponens (üvegpanel-popup, pill napok).

**Táblázat** (ár-összehasonlítás) – fej `--s-felulet-2`, ragadós fej `--s-z-ragados`, sorok `--s-vonal`,
pipa `--s-ok`, nem elérhető: halvány „–” + `aria-label`.

**Háttérdíszek** – aurora: nagy elmosott ellipszisek `--s-aurora-1..3`-ból; rács: 48–60 px-es vonalrács
`--s-racs-vonal`-ból, maszkkal kifelé halványítva. Animáció csak lassú (≥ 12 s) lebegés, `prefers-reduced-motion`-nél áll.

## 7. Mozgás
Időtartamok: `--s-ido-azonnal` 80 · `gyors` 140 · `alap` 220 · `lassu` 420 · `belepes` 640 ms. Görbék: `--s-gorbe`
(kifutó), `--s-gorbe-be`, `--s-gorbe-rugo` (kapcsoló, pill). Görgetésre megjelenés: `opacity` + `translateY(var(--s-elmozdulas))`,
egyszer, `IntersectionObserver`-rel. Csak `transform` és `opacity` animálható. Csökkentett mozgásnál a tokenek ≈ 0-ra váltanak.

## 8. Ikonok
- Készlet: **Lucide** (ISC licenc) – elsődleges; ha hiányzik egy ikon, **Tabler Icons** (MIT). Inline SVG, `currentColor`,
  `stroke-width: var(--s-ikon-vonal)` (1,75), méret `--s-ikon` 20 px / `--s-ikon-nagy` 28 px, `aria-hidden="true"` ha van mellette szöveg.
- Egy oldalon egy készlet; kitöltött és vonalas ikon nem keveredik.
- Funkció-ikon kártyán: 48 px-es üveg-csempe (`--s-r-kicsi`), benne a vonalikon `--s-neon` vagy `--s-kiemelo-2` színben,
  hover: `--s-ragyogas-neon`.

## 9. Képek – art direction
- **Webhely-illusztrációk** (`assets/kepek/`): sötét, absztrakt „műhely-UI” kompozíciók – üvegpaneleken lebegő, olvashatatlan
  absztrakt adatsorok, állapot-pillek, diagramok, egy neon vonalikon üvegcsempén; finom rács, áramköri vezetékek, aurora-foltok,
  bokeh. Paletta kizárólag a márkaszínek. **Nincs** szöveg, szám, márkajelzés, valódi termék vagy arc.
  Hero: a bal ~35% sötét és üres (címsor helye). Iparág: 3:2, funkció: 4:3, OG: 1200×630.
- **Demóképek** (`demo-adat/kepek/`): világos stúdióháttér, lágy talajárnyék, egy tárgy középen, enyhe elforgatás,
  e-kereskedelmi katalógusstílus. Általános (márka nélküli) eszközök: telefon, tablet, laptop, konzol, óra; alkatrészek;
  kiegészítők. Sérült állapotok is (repedt kijelző, törött hátlap, felpúposodott akku) – szervizes demóhoz.
- Formátum: `<picture>` AVIF → WebP, `srcset` a manifestből, `sizes` a rácshoz igazítva, `loading="lazy"` (a hero kivétel:
  `fetchpriority="high"`), mindig `width`/`height` (CLS). `alt` a manifestből (magyar).

### Manifestek
- `tema/sdh-sales-tema/assets/kepek/manifest.json`: `{kulcs: {alt, szelesseg, magassag, valtozatok: {avif: [...], webp: [...]}, szelessegek: [...]}}`.
  Kulcsok: `hero` (480/960/1600/2400), `og` (1200; WebP + **JPG** – a közösségi oldalak nem ismerik az AVIF-et),
  `iparag-{szerviz,ipar,kkv,zrt,allami,egeszsegugy,kereskedelem,egyeb}`,
  `funkcio-{munkalap,ugyfel,eszkoz,raktar,penztar,szamlazas,naptar,sms,ai,mentes,riport,licenc}` (480/960/1600).
  A `valtozatok` tömbjei a `szelessegek` sorrendjét követik; a fájlnév mintája `<kulcs>-<szélesség>.<kiterjesztés>`.
- `demo-adat/kepek/manifest.json`: `{kulcs: {alt, kategoria, szelesseg: 400, magassag: 400, valtozatok}}`;
  kategória: `telefon` (60), `tablet` (30), `laptop` (30), `konzol` (25), `ora` (25), `alkatresz` (40), `kiegeszito` (40).

### Forrás
Magnific generálás elkészült (hero, 8 iparág, 1 demórács), de a letöltő CDN-t a környezet proxyja tiltja (403),
így a képek nem kerülhettek le. A beépített képek **kódból generált SVG-k** (saját rajz, licencmentes), a generátor-szkriptek:
`/home/claude/kepek-eredeti/eszkoz/` (`oldal-kepek.js`, `demo-kepek.js`, `epit.js`, `kontraszt.py`). Részletek: `magnific-koltseg.md`.

## 10. Do / Don't
**Do**
- Minden szín, ív, árnyék, idő token; új szín csak a tokenfájlba, mindkét módra, mérés után.
- Egy elsődleges CTA képernyőnként; a többi másodlagos vagy szellem.
- Üveg csak lebegő rétegen; alatta mindig legyen tartalom/aurora, különben szürke folt.
- Állapot = szín + szöveg/ikon.
- Mobilon 16 px oldalmargó, nincs vízszintes görgetés, érintési cél ≥ 44 px.

**Don't**
- Nincs nyers hex a komponens-CSS-ben; nincs `!important` a tokeneken.
- Nincs neon ragyogás törzsszövegen; nincs átmenetes szöveg 24 px alatt.
- Nincs „Aktív/Inaktív” felirat kapcsoló mellett; nincs szögletes mező, gomb vagy pill.
- Nincs valódi márkalogó, védjegy, termékfotó idegen márkával; nincs stock-fotós „kézfogás”.
- Nincs `backdrop-filter` görgetett listák minden elemén (teljesítmény) – csak konténeren.
