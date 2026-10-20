# Verziónapló-jegyzet – Stúdió (a README-be kerül az összefésüléskor)

## Portál 1.1.0 – Stúdió: a webhely teljes szerkesztője (2026-10-20)

Az „Értékesítés” admin helyén a **Stúdió**: teljes képernyős alkalmazás (WP-admin keret nélkül), a márka neon/üveg
jellegével (üvegpanelek, neon kiemelés a fókuszon és az aktív elemen), világos és sötét módban AA kontraszttal.

- **Navigáció csoportokban, harmonikaszerűen** (egyszerre egy csoport nyitott, a menü asztalon görgetés nélkül elfér):
  Áttekintés · Értékesítés (Rendelések, Banki egyeztetés, Vevők, Leadek, Foglalások, Kuponok) · Licencközpont ·
  Tartalom (Oldalak, Blog, GYIK, Funkciók, Iparágak, Csomagok-modulok-árak, Szolgáltatások, Oktatás, Telepítési útmutató,
  Összehasonlítás, Jogi oldalak, Média) · Megjelenés (Arculat és skinek, Fejléc, Lábléc, Menük, Feliratok, Süti-sáv,
  Szikra) · Kommunikáció (E-mail-sablonok, Űrlapok, Értesítések) · Rendszer (Beállítások, Napló, Revíziók és kuka,
  Export / Import). A régi `#/elemek/<típus>` cím a típus saját panelére visz.
- **Oldalépítő** (`#/oldalak/<id>`): bal oldalt a szekciók (húzás, ↑↓, ki/be, másolás, törlés kétlépcsős gombbal, „+ Szekció”
  könyvtár sematikus bélyegképekkel, csoport-szűrővel), középen az oldal **valódi megjelenítése** (két iframe váltakozva –
  villogás nélkül, a görgetés megmarad; asztali 1280 / tablet 820 / mobil 390 px, világos / sötét), jobbra a kijelölt
  szekció minden mezője (Tartalom / Megjelenés fül). Az előnézetben kattintva kijelöl (szekció: `<template data-s-szakasz>`
  jelölő; mező: a téma `data-s-szerk` jelölése), a szöveg **helyben is írható** (sima szövegnél plaintext, gazdag szövegnél
  formázott; tokenes vagy a megjelenítéskor átalakított szövegnél a jobb oldali mezőbe terel), a kép a médiatárat nyitja.
  Felirat (`f:`), beállítás (`b:` – egy kulcs vagy csoport, pl. `b:ceg`; `b:arculat.logo`), menüpont (`m:`, almenü is),
  tartalomtár-elem (`t:`) kijelölésekor a jobb oldalon a saját szerkesztője nyílik (ezek azonnal mentődnek, minden oldalra
  hatnak). Visszavonás / újra (Ctrl+Z / Ctrl+Y, 100 lépés), **automatikus piszkozat** 2,5 mp után (a látogató a közzétételig
  a régit látja; újranyitáskor a piszkozat betöltődik, eldobható), **Közzététel** (Ctrl+S), Elvetés. **Revíziók**: minden
  közzététel; összevetés és betöltés. **Oldal beállításai**: cím, slug, szülő (körkörös tiltva), sablon, állapot, sorrend,
  kezdőlap, másolat, kuka; SEO (title, leírás, kulcsszó, kanonikus, OG-kép a médiatárból, noindex) találati előnézettel;
  az oldal törzsszövege (ha van).
- **Tartalomtárak**: típusonkénti panelek (lapozott lista – 20 / oldal –, kereső, kategória-szűrő, húzásos sorrend, látszik-
  kapcsoló, másolás, kuka), popupos szerkesztő minden mezővel. Új: **Telepítési útmutató** szerkeszthető (eddig csak a
  data/ fájlból jött), új mezőfajta: `lista` (rövid szövegek listája). E-mail-sablon: **előnézet** mintaadatokkal (a még nem
  mentett szöveggel is) és **próbalevél** a saját címre.
- **Blog**: lista (állapot, kategória, kereső, lapozás, kuka), cikkszerkesztő: cím, gazdag szöveg, állapot, dátum + időpont
  (jövőbeli: ütemezett), kategóriák (új is), címkék, kiemelt kép, kivonat, slug, SEO + noindex.
- **Jogi oldalak**: dokumentumszerkesztő (szöveg, verzió, hatályos, SEO). **Média**: rács (kép / videó / dokumentum),
  kereső, feltöltés a médiatárba, részletek (URL másolása, cím, alt, képaláírás).
- **Fejléc / Lábléc** összetett panelek: logó, név és Demó-cím, főmenü-fa, lábléc-oszlopok, jogi sáv, cégadatok, a
  fejléc / lábléc feliratai. **Menük**: fa-szerkesztő (3 szint, becsukható ágak, oldalválasztó). **Feliratok**: a téma
  minden kódba írt szövege csoportonként, keresővel, „csak a módosítottak” szűrővel, alapra visszaállítással
  (`sdh_sales_feliratok` opció, napló + visszaállítás, export / import). **Arculat**: skin-választó (a téma skinjei) a
  tokenszerkesztő fölött.
- **Űrlapok**: a kapcsolat / ajánlatkérés / partner űrlap mezőinek felirata, kötelezősége, láthatósága (a név és az e-mail
  zárolt) – `sdh_sales_urlap_mezok` szűrő, `sdh_sales_urlap_felulir` opció, export / import.
- **Revíziók és kuka**: kukába tett oldalak és cikkek (visszaállítás, végleges törlés kétlépcsős gombbal), törölt
  tartalom-elemek (visszaállítás a naplóból), a legutóbbi oldal-revíziók.
- **Élő oldalon**: bejelentkezett szerkesztőnek bal alul lebegő „Oldal szerkesztése” gomb (semleges osztálynévvel,
  látogatónak semmi), ami a Stúdió oldalépítőjét az adott oldalra nyitja.
- REST (mind admin + nonce): `/admin/feliratok`, `/admin/oldal/{id}/vazlat|vazlat/elvet|reviziok|masol|kuka|vissza|kezdolap`,
  `/admin/blog…`, `/admin/media…`, `/admin/kuka…`, `/admin/email/elonezet|proba`, `/admin/urlapok`. Az oldal-mentés új
  mezői: slug, szulo, sorrend, sablon, tartalom, seo.noindex / kanonikus / og_kep.
- Kompatibilitás: a `sdh_sales_admin_panelek` szűrő és a `window.PA.panel()` megmaradt (új mezők: `menu`, `tipusok`,
  `beallitas`); a PA-felület bővült: `mediaValaszt`, `oldalLista`, `oldalValaszto`, `naptar`, `rajzolo`, `beallitasNezet`,
  `elemSzerkeszt`, `menuSzerkeszto`, `feliratKartya`, `utBont`, `vazlatKep`. Az `admin-tartalom.js` megszűnt
  (a Stúdió `assets/studio/*.js` fájljai váltják).
- Tesztek: t330–t335 (jogosultság, szerkesztő mód és jelölés, feliratok, oldalépítő háttér, blog / média / kuka, űrlapok /
  e-mail / séma), t231 / t234 / t239 igazítva; `e2e/admin-vizualis.py` a Stúdió minden új paneljére.
