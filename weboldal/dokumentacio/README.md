# Értékesítő webhely – README és verziónapló

A CRM (a Műhely) értékesítő webhelye: bemutató oldalak, webshop, fizetés, automatikus licenc-kiszolgálás a licencközponton
át, számla, időpontfoglalás, GYIK, tudásbázis, AI-asszisztens, szerkeszthető tartalom – és a külön kipróbáló demó.
Három csomag, mind a `weboldal/` alatt, a Műhely telepítőjébe egyik sem kerül (`.gitattributes`: `export-ignore`).

| Csomag | Mappa | Telepített név (csomagoló) | Verzió |
|---|---|---|---|
| Értékesítő bővítmény | `sales/sdh-sales/` | `portal` (Portál) | 1.0.0 |
| Téma | `tema/sdh-sales-tema/` | `kirakat` | 1.0.0 |
| Demó-korlátozó | `demo/sdh-demo/` | `bemutato` (Bemutató) | 1.0.0 |

Verzió 3 helyen: a fejléc `Version:`, a konstans (`SDH_SALES_VERSION` / `SDH_TEMA_VERSION` / `SDH_DEMO_VERSION`), és ez a
verziónapló. Csomagolás: `sh weboldal/eszkozok/csomagol.sh` → `weboldal/dist/portal-<v>.zip`, `kirakat-<v>.zip`,
`bemutato-<v>.zip` (semleges mappa- és fájlnevek, szivárgás-ellenőrzéssel; lásd `REJTES.md`).

## Kötelező olvasmányok
- `weboldal/REJTES.md` – a legfontosabb szabály: a kimenetben sehol nincs platform- és eredet-nyom. Üzemeltetés:
  `dokumentacio/rejtes-uzemeltetes.md` (wp-config, Apache / nginx, Cloudflare).
- `weboldal/ARCHITEKTURA.md` – szerződés: mappák, beállítások, adatbázis, a fő folyamat (rendelés → fizetés → licenc →
  számla → e-mail), tartalomformátumok, téma ↔ bővítmény.
- `weboldal/DONTESNAPLO.md` – feltételezések és döntések.
- Kutatás: `dokumentacio/kutatas/` (nevek + domain, konkurencia, árazás, kulcsszavak). Arculat: `dokumentacio/arculat.md`.
  Fizetés-automatizálás: `dokumentacio/fizetes-automatizalas.md`. Számlázz.hu: `dokumentacio/szamlazz.md`.
  SEO-ellenőrzőlista: `dokumentacio/seo-ellenorzolista.md`. Demó: `dokumentacio/demo.md`. Független ellenőrzés:
  `dokumentacio/fuggetlen-ellenorzes.md`.

## Telepítés (röviden)
1. Friss webhely (PHP 8.1+, MySQL 8 / MariaDB 10.6+, HTTPS), **a webhely nyelve magyar** (Beállítások › Általános › A honlap
   nyelve: Magyar – különben a dátumok angolul jelennek meg), időzóna Europe/Budapest. A `rejtes-uzemeltetes.md` wp-config- és
   szerver-beállításai.
2. Bővítmények › Új › Feltöltés: `portal-<v>.zip` (bekapcsolás: táblák, szerepkör `sdh_vevo`, kezdőtartalom – 84 oldal,
   22 cikk, GYIK, árak, menük – a `data/` mappából), Megjelenés › Témák: `kirakat-<v>.zip` (bekapcsolás).
3. Admin › Értékesítés (`admin.php?page=ertekesites`): Beállítások – terméknév (**egy mező**, mindenhol cserél), cégadatok
   (név, adószám, cím, bankszámla + IBAN/BIC a QR-hoz), e-mail feladó, áfa, licencközpont (helyi vagy távoli + nyilvános kulcs),
   Számlázz.hu (teszt-kulcs → `szamlazz.teszt`; élesre kapcsolás Norbi döntése), fizetés-automatizálás, Turnstile, Szikra
   (API-kulcs, keret), Rejtés (titkos belépő cím → ezt jegyezd fel), SEO (sameAs, logó, OG-kép, AI-botok).
4. Licencközpont: ha külön webhelyen fut, a Központ Beállítások › Értékesítő webhely mezőjébe az értékesítő oldal nyilvános kulcsa.
5. Ellenőrzés: Beállítások › Rejtés › **Ellenőrzés most** (0 találat), majd a Playwright-bejáró:
   `python3 weboldal/tesztek/e2e/rejtes-bejaras.py https://<webhely> 200`.
6. Demó: külön webhely a demó-aldomainen – `dokumentacio/demo.md`.

## Fejlesztés és tesztek
- Tesztoldal: WP 6.8.3 + SQLite (`sh weboldal/eszkozok/sajat-teszthely.sh <név>`), a bővítmények szimlinkelve.
  Futtatás: `SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/home/claude/env/<név>/wp/wp-load.php SDH_TESZT_DB=… SDH_TESZT_ALAP=…
  php weboldal/tesztek/futtat.php [szűrő]` (tesztenként egy összegző sor). Böngészős: `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:<port> -t
  /home/claude/env/<név> weboldal/eszkozok/router.php` (az útvonal-álneveket is kiszolgálja), majd
  `tesztek/e2e/t241-tema-bongeszo.py`, `rejtes-bejaras.py`, `*-vizualis.py`.
- Téma-CSS: `assets/css/*.css` → `php tema/sdh-sales-tema/bin/epit.php` (esbuild) → `assets/dist/*.min.css`.
- Tudásbázis (Szikra): a Műhely `sugo/` fejezeteiből `php weboldal/eszkozok/tudas-epit.php` – a súgó változásakor újra.

## Verziónapló

### 1.1.0 – Stúdió, licenckezelés, skinek (2026-10-20)
Portál 1.1.0 · Kirakat 1.1.0 · Licencközpont 2.2.0. A neon + üveg arculat változatlan.

#### Portál 1.1.0 – Stúdió: a webhely teljes szerkesztője (2026-10-20)

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

#### Portál (a következő verzióban)
- **A licencközpont a Portál része – egy telepítés.** A `portal-<v>.zip` hozza (`licenc/kozpont.php`,
  `licenc/includes/class-k*.php`, semleges nevekkel, a kiszolgált fájlokban nincs rendszer- / márkanév). Ha a különálló
  Központ-bővítmény aktív, az fut (nincs dupla betöltés); ha nem, a beágyazott (`plugins_loaded` -100). Azonos táblák és
  beállítások: a kettő között adatvesztés nélkül lehet váltani. A „helyi” licencközpont-mód így alapból működik.
- **Új admin-csoport: Licencközpont** (a Vevők és a Tartalom között):
  - **Licencek** – 6 számkártya (összes, aktív, 30 napon belül lejár, lejárt, felfüggesztett, próba), szűrők, keresés
    (kulcs, cég, e-mail, adószám kötőjellel / nélkül, domain és címke a telepítés jelentéséből), rendezés, lapozás.
    Részletek (popup, lapfülekkel): áttekintés (vevő + licenc), modulok (kapcsolóval, azonnal), telepítések
    (leválasztás), letöltő linkek, a webes rendelések és számlák, napló. Műveletek: hosszabbítás (egy ciklus / +1, 3, 6,
    12 hó / dátumra / lejárat nélkül), csomagváltás (a csomag moduljaival és kereteivel, vagy csak névvel), kulcs küldése
    e-mailben (új letöltő linkkel), szerkesztés (keretek, díj, ciklus, csatorna, jogok, üzenet a vevőnek, megjegyzés),
    felfüggesztés / visszaállítás, új kulcs (a webes rendeléseken is cseréli), új letöltő link (a régiek érvénytelenek).
    Kétlépcsős gombok, minden művelet a licencközpont és a Portál naplójában. Új licenc kézzel (meglévő / új vevő).
  - **Telepítések** – a vevők rendszerei: jelentkezik / nem jelentkezik / gondban / régi verzió, keresés; részletek,
    címke és jegyzet, távoli műveletek (diagnosztika, mentés, frissítés, üzenet a vevőnek, támogatási hozzáférés),
    belépő linkek, leválasztás.
  - **Kiadások** – vevői zip feltöltése (stabil / teszt csatorna), kiadás mindenkinek / vissza tesztre, törlés.
  - A Vevők és a Rendelés popupban a licenckulcsra kattintva a licenc nyílik meg. Parancspaletta: „Új licenc”,
    „Lejáró licencek”.
  - **Haladó nézet**: a teljes licencközpont-felület (rendszerfigyelés, mérések, támogatás, beállítások) rejtett oldalként
    (`admin.php?page=licenckozpont`), „Licencközpont” címmel; beágyazva nincs külön felső menü, belépés utáni
    átirányítás és admin-sáv gomb.
  - Távoli licencközpont-módban a panelek ezt jelzik, és a távoli központra mutatnak (a REST 409).
- Új e-mail-sablon: `licenc_kulcs` (kézi kulcsküldés).
- REST (`portal/v1`, csak admin): `admin/licenc/allapot`, `admin/licencek`, `admin/licenc/{id}` (GET / POST),
  `admin/licenc` (POST), `admin/licenc/{id}/muvelet`, `admin/licenc/vevok`, `admin/licenc/peldanyok`,
  `admin/licenc/peldany/{id}` (+ `/muvelet`, `/levalaszt`), `admin/licenc/kiadasok`, `admin/licenc/kiadas` (+ `/{id}`),
  `admin/licenc/naplo`.
- Tesztek: t320 (beágyazott / különálló / nincs – al-folyamatokban, a tesztoldal beállításától függetlenül), t321
  (jogosultság, távoli mód), t322 (lista, szűrők, keresés, lapozás; 300 licenc: 11–25 ms), t323 (minden művelet), t324
  (létrehozás, módosítás, ellenőrzések), t325 (telepítések, kiadások), t326 (csomag: szerkezet, nevek, szivárgás, PHP-lint).
  Böngészős: `tesztek/e2e/licenc-vizualis.py` (az admin-vizualis.py mérőivel) – 225 állapot, 0 hiba.

#### Licencközpont 2.2.0 (`kozpont/sdh-kozpont`)
- Beágyazható: egyszer töltődik (ha egy másik másolat már fut, a második kimarad), a csomagban semleges fájlnevekkel
  is (`class-k*.php`), `SDH_KOZPONT_BEAGYAZVA` / `SDH_KOZPONT_URL` állandók, `sdh_kozpont_felulet_url` és
  `sdh_kozpont_eszkoz_url` szűrő. Beágyazva a kiadások semleges mappába (`uploads/licenc-kiadas/kiadas-<v>-….zip`).
- A felület kiszolgált fájljaiból (kozpont.js / .css) kikerült a márka- és rendszernév (`window.LK_BOOT`, „Licencközpont”,
  „Rendszer-admin”); különálló módban a cím továbbra is „SDH Központ”.
- t94 a 2.2.0-ra igazítva; a Műhely teljes tesztcsomagja zöld (75), t92 / t94 a különálló Központtal.
- (A Műhely `README.md` verziónaplójába: „Központ 2.2.0 – beágyazható a Portálba”.)

#### Kirakat 1.1.0 + Portál (arculat) – szerkeszthető feliratok, kattintható jelölés, skinek (2026-10-20)

A kinézet nem változott: az alapbeállítás („Neon” skin) pixelre a korábbi neon + üveg arculat (nincs arculat-CSS a kimenetben).

- **Feliratok (Stúdió-szerződés):** a téma minden kódba írt, látogatónak látható szövege (203 db – fejléc, lábléc, kereső,
  blog, árak, 404, tudásbázis, a JS-nek adott feliratok is) felülírható a `sdh_sales_feliratok` opcióból.
  - Jegyzék: `tema/inc/feliratok.php` (kulcs → alap, csoport, leírás „Hol: …”), lekérdezés: `sdh_tema_feliratok()`.
  - `sdh_tema_f($kulcs, $alap)`: felülírás (HTML nélkül, max. 500 karakter, tokencserével), üres → alap.
  - `sdh_tema__($s)` a jegyzékbeli szövegnél automatikusan `sdh_tema_f`-en megy át (a hívási helyek változatlanok).
    Ugyanaz a szöveg több helyen = egy kulcs. Új felirat: a sablonba `sdh_tema__('…')`, és egy sor a jegyzékbe (t245 őrzi).
- **Kattintható szerkesztés:** `sdh_tema_szerk($ut)` (a `SDH_Sales_Studio::attr()` vagy a `sdh_tema_szerk_attr` szűrő
  attribútuma, különben üres). Utak: `o:{post}/{szekció}` (a szekció egésze), `o:{post}/{szekció}/{mező}`,
  `o:…/{lista}/{elem}/{almező}` (az eredeti sorszámmal), `o:…/cta/{n}` | `gomb` | `gomb2` (gombok), `f:{felirat}`,
  `b:termeknev` | `b:szlogen` | `b:ceg` | `b:arculat.logo`, `m:{fo|lablec|jogi}/{i}` és almenüpont `m:fo/{i}/{j}`,
  `t:{tipus}/{kulcs}[/{mező}]` (csomag, modul, funkció, iparág, GYIK, konkurens, esemény, telepítés).
  A szekció-diszpécser `_post` mezőt ad a szekcióknak (a mount-opciókba nem kerül).
- **Skinek:** `SDH_Sales_Arculat::skinek()` – Neon (alap), Ibolya, Kobalt, Smaragd, Borostyán, Magenta; mindegyik világos +
  sötét. Csak a kiemelő-családot cserélik (kitöltés, halvány tónusok, átmenetek, aurora, ragyogás, fókusz); háttér, felület,
  szöveg, állapotszínek maradnak. Beállítás: `arculat.skin` (`ment(['skin' => …])`, előnézet: `terkep(['skin' => …])`),
  `allapot()` → `skin`, `skinek` (név, leírás, mintaszínek). A tokenenkénti felülírás a skin után jön (az nyer).
  WCAG AA: mindhárom szín szövegként ≥ 4,5:1 a háttéren/felületen, a kitöltésen a felirat ≥ 4,5:1 (t246 méri).
- **Javítás:** a tokenenkénti világos felülírás rendszer-világos módban eddig alulmaradt a téma szelektorával szemben
  (kisebb specifikusság) – most ugyanazzal a szelektorral íródik ki.
- Tesztek: t245 (440 ellenőrzés), t246 (176 ellenőrzés).

### 1.0.1 – útvonal-álnevek csak működő szerver mellett (2026-10-20)
- **Portál**: a semleges útvonalak (`/static/…`, `/media`, `/lib`) csak akkor élnek, ha egy próba-kérés igazolja, hogy a webszerver
  kiszolgálja őket (admin-oldalon óránként, sikertelenül 10 percenként újrapróbál). Addig a fájlok a valódi helyükről töltődnek,
  és egy admin-értesítés mondja meg a teendőt. Korábban szabály nélküli nginx-en (pl. Local) minden CSS/JS 404 lett → stílus
  nélküli, lassú oldal. Az Apache-szabályba a valódi útvonalak tiltása is csak sikeres próba után kerül. Teszt: t312.
- **Portál**: a leírásból kikerült a belső név. Admin-sáv stílusa bejelentkezve megmarad.
- **Kirakat**: a téma neve „Kirakat” (volt: „Portál”), előnézeti kép (`screenshot.jpg`).

### 1.0.0 – első kiadás (2026-10-20)
- **Téma** (`kirakat`): 32 szekciótípus (hero, rácsok, árak, kalkulátor, összehasonlítás, GYIK, űrlapok, foglalás, demó-indítás,
  blog, tudásbázis, státusz, jogi, fiók, kosár, pénztár, oktatás, telepítő, események…), üveg + neon arculat, sötét / világos
  mód (festés előtt beállítva), önhosztolt betűk (Bricolage Grotesque, Onest, JetBrains Mono), kritikus CSS a fejben, a többi
  nem blokkol, nincs jQuery, azonnali kereső (Ctrl+K / „/”, elírás- és ékezettűrő, előre épített index), saját dátumválasztó,
  tudásbázis-oldalak, morzsa, kapcsolódó blokkok. Labor: mobil LCP ≤ 460 ms, CLS 0 (Chromium, localhost; Lighthouse nem
  futott – az npm-tároló zárt). t240 (421 ellenőrzés), t241 (24 nézet).
- **Értékesítő bővítmény** (`portal`): tartalom-tár (csomag, modul, szolgáltatás, kupon, GYIK, funkció, iparág, lecke, e-mail,
  menü, konkurens, esemény) a `data/` kezdőtartalommal (84 oldal, 50 funkció, 8 iparág, 176 GYIK, 22 cikk, 31 lecke, 10 jogi
  oldal tervezetben); ár (szerveren számol, JS tükör – 300 véletlen eset bájtra azonos), kosár, rendelés (csak vállalkozásoknak,
  adószám-ellenőrzés), kupon; fizetés: átutalás díjbekérővel + MNB azonnali-fizetési QR (saját QR-kódoló, 46/46 kód
  visszafejthető), banki kivonat-import (8 bank + CAMT.053, közlemény-párosítás), Barion / SimplePay (alapból ki), kétlépcsős
  „Fizetve”, Számlázz.hu fizetettség-lekérdezés; egyetlen `fizetve()` belépési pont (idempotens, versenyhelyzetben pontosan
  egyszer); kiszolgálási lánc (Központ → végszámla → üdvözlő e-mail licenckulccsal és egyszer használható letöltéssel →
  időpont → értesítés; újrapróba, 63–212 ms); Számlázz.hu díjbekérő → végszámla automatikusan (Norbi döntése), teszt módban
  „MINTA”; időpontfoglalás (sávok, ütközésvédelem, ICS, emlékeztető, lemondás, iCal-feed, Google Naptár opció); ügyfélfiók
  e-mailes belépő linkkel (jelszó nélkül); igényfelmérő űrlap; Szikra AI-chat (tudásbázis 30 fejezet / 1 172 darab, BM25,
  eszközök: csomagajánló, lead csak hozzájárulással, időpont, átadás; napi és havi keret; prompt-injekció elleni védelem) +
  oktató mód (leckék, kvíz, haladás, tanúsítvány); süti-hozzájárulás (Consent Mode v2, naplózva, GPC); SEO (séma Rank Math
  mellett duplikáció nélkül, meta-tartalék, saját sitemap, robots AI-bot kapcsolóval, llms.txt + llms-full.txt, kereső-index,
  belső linkháló); CRM-szerű admin (szekcióépítő élő előnézettel, minden tartalom / ár / menü / arculat / beállítás
  szerkeszthető, export / import, változásnapló visszaállítással, Ctrl+K); biztonság (Turnstile, CSP nonce-szal, fejlécek,
  rate limit, TOTP admin-2FA, titkok titkosítva); **rejtés** (REST `/api/portal/v1`, útvonal-álnevek, titkos belépő, fej- és
  fejléc-takarítás, kimeneti szűrő, önellenőrzés). 57 teszt (t200–t309), mind zöld.
- **Licencközpont 2.1.0** (a Műhely `kozpont/` mappájában): aláírt kiszolgálási végpont, egyszer használható letöltés,
  licenckulcs-előtag beállítás – a Műhely README 0.99.5 bejegyzése.
- **Bemutató 1.0.0** (demó-korlátozó, `bemutato`; külön webhelyen a Műhely vevői csomagjával, tesztüzemben): generátor
  (~10 000 kitalált rekord: ügyfél, eszköz, munkalap tételekkel és hibákkal 18 hónapra, termék készlettel, szolgáltatás,
  pénztár, naptár + 170 készülékfotó és 230 átvételi fotó; mért 1,5–4,3 s SQLite-on), pillanatkép + éjszakai visszaállítás
  03:00-kor (48 tábla, ~40 900 sor, 942 KB; mentés 0,4–1,3 s, visszaállítás 1,4–4,1 s), egykattintásos indítás
  (`?bemutato=indit`, Turnstile, 24 órás ideiglenes fiók, jelszó soha nem látszik), IP-nként 10 új rekord / 24 óra (érthető
  magyar hibaüzenettel a felugró ablakban), a látogatói rekordok 24 óra után törlődnek, kimenő e-mail és SMS csak a
  „Kimenő üzenetek” nézetbe kerül, Számlázz.hu csak teszt-kulccsal és „MINTA”-ként, tiltva: frissítés, modulok, külső mentés,
  fiókkezelés, licenc, érzékeny beállítások, import / export / anonimizálás (szerepkör + menü + szerveroldali tiltás, 117
  ellenőrzés), „BEMUTATÓ” sáv (visszaállításig hátralévő idő, megmaradt keret). t290–t299, böngészős folyamat 13/13.
- Nyitott (Norbi): terméknév és domain választása (`kutatas/nevek.md`), árszint jóváhagyása, Számlázz.hu teszt-kulcs,
  bankadatok, Turnstile-kulcs, Szikra API-kulcs, jogász (`[DÖNTENDŐ]` pontok), Magnific-képek letöltése
  (`magnific-koltseg.md`), szerverbeállítások (`rejtes-uzemeltetes.md`).
