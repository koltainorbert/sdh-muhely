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
