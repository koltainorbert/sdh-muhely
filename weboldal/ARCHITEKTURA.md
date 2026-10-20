# Értékesítő webhely – architektúra és szerződés (minden ügynök kötelező olvasmánya)

> **ELSŐBBSÉG: `weboldal/REJTES.md`** – a kimenetben sehol nincs WordPress- vagy SDH-nyom. Ahol ez a fájl mást mond
> (`data-sdh`, `window.SDH`, `sdh-sales/v1`, `sdh_suti_id`, „SDH CRM”), a REJTES.md az érvényes.

Ez a fájl a közös megállapodás. Aki kódot vagy tartalmat ír a `weboldal/` alá, **ehhez tartja magát**, és csak a
**saját mappájába / fájljaiba** ír (lásd „Tulajdonosok”). Ha a szerződésen változtatni kell, szólj a vezetőnek,
ne írd át magad.

## 0. Projekt-szabályok (a Műhelyből örökölve, itt is kötelezők)
- **Cégadat, név, ár, bankszámla, e-mail-cím NEM lehet a kódban.** Minden ilyen a beállításokból jön
  (`SDH_Sales::b()`), a kezdőértékek a `sales/sdh-sales/data/*.json`-ból (helyőrzők). A termék neve **mindenhol**
  `{termeknev}` token / `SDH_Sales::termeknev()` – sehol nincs beégetve. Cég: `{ceg}`, e-mail: `{ceg_email}` stb.
- Színek, betűk, ívek, árnyékok: **CSS-változók** (`--s-…`), adminból állíthatók (Arculat).
- Natív dátumválasztó (`<input type="date">`) **tilos** – saját naptár-választó (`sdh-datum` komponens a témában).
- `confirm()` / `alert()` **tilos** → **kétlépcsős gomb** (`data-ketlepcsos`: 1. katt „Biztos? – katt újra”, 4 mp).
- **Kapcsoló** (`.s-kapcsolo`) felirat nélkül (nincs Aktív/Inaktív), körülötte ≥ 6 px levegő.
- Minden mező, legördülő, gomb, pill **lekerekített** (`--s-r-mezo`, `--s-r-gomb`, `--s-r-pill`). Többsoros: max 15 px.
- Áfa: **27%** (beállításban `afa` = 27, de minden számítás a beállítást használja).
- Felvitel popupban; asztalon a popup nem görget (admin).
- A menüben csak az áll, amit a felhasználó meg is nyithat.
- Fronton **nincs jQuery**, vanilla JS (ES2019+), `defer`. Nincs page builder.
- Nem fejlesztőnek szóló, látogatónak látható szövegben nincs „WordPress / plugin / bővítmény / GitHub”.
- Nyelv: HU az alap; minden látogatói szöveg fordítható (`__('…', 'sdh-sales')`, téma: `'sdh-sales-tema'`).
- PHP 8.1+, WordPress 6.4+ (teszt: 6.8.3 + SQLite). `declare(strict_types=1)` nem kötelező; típusok igen.
- Minden REST-végpont: jogosultság-ellenőrzés, nonce (bejelentkezett), rate limit (`SDH_Sales_Rest::korlat()` – vagy az útvonal-regisztráció `[név, db, mp]` paraméterével),
  bemenet-tisztítás, kimenet escapelés.

## 1. Mappák és tulajdonosok
```
weboldal/
  ARCHITEKTURA.md            – ez (vezető)
  tema/sdh-sales-tema/       – TÉMA (front ügynök)
  sales/sdh-sales/           – ÉRTÉKESÍTŐ BŐVÍTMÉNY (több ügynök, fájlonként – lásd lent)
  demo/sdh-demo/             – DEMÓ-KORLÁTOZÓ BŐVÍTMÉNY (demó ügynök)
  demo-adat/                 – 10 000 rekord generátora + képek (demó ügynök)
  dokumentacio/              – README (= verziónapló), telepítés, üzemeltetés, kutatás (vezető + kutató)
  tesztek/                   – PHP-tesztek + e2e (QA; minden ügynök a saját tesztjét ide írja: tNNN-*.php)
  eszkozok/                  – build-szkriptek (zip, tudásbázis, index, képoptimalizálás)
```
| Fájl / mappa a `sales/sdh-sales/` alatt | Tulajdonos |
|---|---|
| `sdh-sales.php`, `includes/class-sdh-sales.php`, `-schema.php`, `-naplo.php`, `-rest.php`, `-front.php`, `-tartalom.php` (mag; a tartalom-tár API-ja a fájl fejlécében) | vezető |
| `assets/front/kozos.js` (közös front-segéd, lásd `-front.php` fejléce) | sales-backend |
| `-katalogus.php`, `-ar.php`, `-kosar.php`, `-rendeles.php`, `-kupon.php`, `-fizetes*.php`, `-qr.php`, `-bank-import.php`, `-biztonsag.php`, `-fiok.php` | sales-backend |
| `-admin*.php`, `assets/admin.*`, `-szekcio.php` (szekció-sémák az adminnak), `-arculat.php`, `-exportimport.php` | admin |
| `-kiszolgalas.php`, `-kozpont-kapcsolat.php`, `-email.php`, + **Központ 2.1** (`kozpont/sdh-kozpont`) | licenc-integráció |
| `-szamlazz.php`, `-naptar.php` | számlázás + naptár |
| `-szikra*.php`, `assets/szikra.*`, `-oktatas.php` | AI |
| `-gyik.php`, `-kereso.php`, `-seo.php`, `-llms.php` | SEO (a GYIK-adatot a tartalom ügynök írja) |
| `-suti.php`, `assets/suti.*` | jogi + süti |
| `-egyedi.php` (igényfelmérő) | sales-backend |
| `data/` (JSON, MD tartalom) | tartalom / jogi / kutató (fájlonként, lásd 6.) |

## 2. Elnevezés
- Bővítmény: `sdh-sales` (Plugin Name: „SDH Értékesítés”), osztályok `SDH_Sales_*`, prefix `sdh_sales_`,
  konstansok `SDH_SALES_VERSION`, `SDH_SALES_DIR`, `SDH_SALES_URL`, `SDH_SALES_FILE`.
- Téma: `sdh-sales-tema`, függvények `sdh_tema_*`. Demó: `sdh-demo`, `SDH_Demo_*`.
- Táblák: `{$wpdb->prefix}sdh_sales_<nev>` – csak `SDH_Sales_Schema::t('nev')`-vel.
- REST névtér: `portal/v1` (előtag `api` → `/api/portal/v1/…`). Nyilvános útvonalak: `/ar`, `/kosar/ellenoriz`, `/rendeles`, `/rendeles/(?P<azon>)/allapot`,
  `/kupon`, `/foglalas/savok`, `/foglalas`, `/lead`, `/egyedi`, `/szikra`, `/kereso`, `/suti`, `/fizetes/barion/ipn`,
  `/fizetes/simplepay/ipn`, `/fiok/belepes` (magic link). Admin: `/admin/<…>` (`manage_options` vagy `sdh_sales_admin` jog).
- Opciók: `sdh_sales_beallitas` (egy tömb), `sdh_sales_db` (séma verzió), `sdh_sales_kulcspar` (Ed25519, titkosítva).
- Cron: `sdh_sales_cron` (5 percenként: kiszolgálási lánc újrapróba, emlékeztetők, lejárat előtti levél, takarítás).

## 3. Beállítások (`SDH_Sales::b($kulcs, $alap)` / `SDH_Sales::b_ment(array)`)
Egy tömb, pontozott kulcsok csoportosítanak. A kezdőértékek a `data/beallitas-alap.json`-ból jönnek.
Fontos kulcsok (bővíthető, de NE nevezd át):
- `termeknev` („Munkora” helyőrző), `termeknev_rovid`, `szlogen`
- `ceg.nev`, `ceg.adoszam`, `ceg.cim`, `ceg.email`, `ceg.telefon`, `ceg.bankszamla`, `ceg.iban`, `ceg.bic`, `ceg.cegjegyzek`, `ceg.kepviselo`
- `afa` (27), `penznem` („HUF”), `eves_kedvezmeny` (%), `nyelvek` (["hu"])
- `fizetes.atutalas` (bool), `fizetes.barion.*`, `fizetes.simplepay.*`, `fizetes.automatizalas` ("import" | "szamlazz" | "kartya" | "kezi")
- `kozpont.mod` ("helyi" | "tavoli"), `kozpont.url`, `kozpont.nyilvanos` (a központ kulcsa, TOFU)
- `szamlazz.agent_kulcs` (titkosítva), `szamlazz.teszt` (bool), `szamlazz.elotag`, `szamlazz.automatikus` (**true**: sikeres fizetés után a végszámla magától megy – Norbi döntése, 2026-10-19)
- `email.felado`, `email.ertesites` (Norbinak), `naptar.*`, `szikra.*` (kulcs titkosítva, modell, keret), `turnstile.*`, `seo.*`, `suti.*`, `demo.url`, `arculat.*` (CSS-változók értékei)

Titok (API-kulcs, Agent-kulcs, Barion POSKey, SimplePay SECRET) **csak** `SDH_Sales::titkos_ment()/titkos()`-sal
(sodium secretbox, kulcs a `wp-config` sóiból) – soha nyíltan, soha REST-válaszban, soha exportban.

## 4. Adatbázis (`SDH_Sales_Schema`, `DB_VERSION`)
| Tábla | Mezők (lényeg) |
|---|---|
| `rendeles` | id, azon (+ `fizetett`, `email`, `hatarido` mezők – lásd a sémafájlt) (pl. `R-2026-000123`, egyedi), allapot (`uj`,`dijbekero`,`fizetesre_var`,`fizetve`,`kiszolgalva`,`sikertelen`,`torolve`,`visszaterites`), tipus (`uj`,`megujitas`,`bovites`,`szolgaltatas`), vevo (JSON: cég, adószám, cím, kapcsolattartó, e-mail, telefon), tetelek (JSON), netto, afa, brutto (egész Ft), penznem, ciklus (`havi`,`eves`,`egyszeri`), kupon, fizmod (`atutalas`,`barion`,`simplepay`), kozlemeny (egyedi, a párosításhoz), licenc_kulcs, kozpont_licenc_id, szamla_dijbekero, szamla_vegszamla, lanc (JSON: lépések állapota), user_id, ip_hash, letrehozva, modositva, fizetve_ido |
| `fizetes` | id, rendeles_id, forras (`bank_import`,`szamlazz`,`barion`,`simplepay`,`kezi`), tranzakcio (egyedi forrásonként → idempotencia), osszeg, ido, nyers (JSON), allapot |
| `tartalom` | id, tipus (`csomag`,`modul`,`szolgaltatas`,`kupon`,`gyik`,`iparag`,`funkcio`,`lecke`,`email`,`jogi`,`menu`,`lablec`,`szekcio_sablon`…), kulcs, nyelv („hu”), cim, adat (JSON), sorrend, aktiv, modositva — egyedi (tipus, kulcs, nyelv) |
| `naplo` | id, ido, tipus, szint, szoveg, ref_tipus, ref_id, elotte (JSON), utana (JSON), ki — változásnapló + visszaállítás |
| `lead` | id, forras (`kapcsolat`,`ajanlat`,`szikra`,`egyedi`,`partner`,`demo`), adat (JSON), allapot, ido, ip_hash |
| `foglalas` | id, tipus (`demo`,`telepites`,`oktatas`,`support`), kezdet, veg (UTC datetime), nev, email, telefon, ceg, megjegyzes, allapot, token (lemondás), rendeles_id, google_id, emlekezteto_kuldve, ido |
| `korlat` | kulcs (pl. `szikra:<iphash>:2026-10-19`), db, lejar — rate limit + napi keret |
| `suti` | id, ido, azon (anonim süti-ID), kategoriak (JSON), verzio, ip_hash — naplózott hozzájárulás |
| `szikra` | id, ido, munkamenet, szerep, szoveg, token_be, token_ki, ip_hash — beszélgetésnapló (30 napig) |
| `fiok_link` | token_hash, user_id/email, lejar — magic link |
IP-t **soha nem** tárolunk nyersen: `SDH_Sales::ip_hash()` (sózott SHA-256, naponta forgó só a rate limithez, állandó só a naplóhoz).

## 5. A fő folyamat (sales-backend → licenc-integráció → számlázás)
1. Látogató kosara a böngészőben (localStorage), árat a szerver számol: `SDH_Sales_Ar::szamol(array $tetelek, array $opciok): array`
   (`opciok`: ciklus, felhasznalo, telephely, kupon) → `{sorok[], netto, afa, brutto, kedvezmeny, hibak[]}`. A JS ugyanezt a
   szabályt tükrözi a kalkulátorhoz, de a rendelésnél **mindig a szerver** ára számít.
2. `POST /rendeles` → `SDH_Sales_Rendeles::letrehoz()` → állapot `dijbekero`; Számlázz.hu díjbekérő (ha be van kötve);
   e-mail a díjbekérővel + átutalási adatok + **MNB QR** (`SDH_Sales_Qr::png()/svg()`).
3. Fizetés beérkezése bármelyik forrásból → **egyetlen belépési pont**:
   `SDH_Sales_Rendeles::fizetve(int $rendeles_id, string $forras, string $tranzakcio, int $osszeg, array $nyers = []): bool`
   – idempotens (forrás+tranzakció egyedi; már fizetett rendelésnél nem indít újra), részfizetés kezelése, napló, majd
   `do_action('sdh_sales_fizetve', $rendeles_id)`.
4. `SDH_Sales_Kiszolgalas::indit($rendeles_id)` (a hookra) → lépésgép a `rendeles.lanc` mezőben, minden lépés
   `{allapot: var|ok|hiba, probalkozas, uzenet, ido}`, újrapróba a cronból exponenciálisan. Lépések sorrendben:
   `kozpont` (vevő + licenc + modulok; megújításnál `megujit`, bővítésnél modul hozzáadás) → `szamla` (végszámla a
   díjbekérőből, PDF letöltve a védett mappába) → `email` (üdvözlő: licenckulcs, letöltő link, telepítési útmutató,
   fiók-belépő link, számla PDF) → `idopont` (telepítési / support időpont felajánlása linkkel) → `ertesites` (Norbinak) → kész.
   Egy lépés csak egyszer fut sikeresen (a sikeres eredmény a láncban tárolva, újrafuttatáskor kihagyva).
5. Megújítás / bővítés: a fiókból vagy a lejárat előtti levél linkjéből új rendelés `tipus=megujitas|bovites`, `licenc_kulcs`
   kitöltve. Sikertelen fizetés (kártya): `sikertelen` + levél újrapróba-linkkel. Lejárt díjbekérő (N nap): emlékeztető, majd törlés.

Központ-kapcsolat (`SDH_Sales_Kozpont_Kapcsolat`):
- `helyi` mód: a Központ ugyanazon a webhelyen fut → közvetlen PHP-hívás `SDH_Kozpont_Kiszolgalas::feldolgoz(array)`.
- `tavoli` mód: `POST {kozpont.url}/wp-json/sdh-kozpont/v1/kiszolgalas`, törzs: `{adat: base64(json), alairas: base64(Ed25519)}`,
  az értékesítő oldal saját kulcspárjával aláírva; a Központ a beállításaiban tárolt értékesítői nyilvános kulccsal ellenőriz,
  időbélyeg ±300 s, `nonce` egyszer használható; a válasz a Központ kulcsával aláírva (a meglévő `SDH_Kozpont::alair()`).
- Kérés: `{muvelet: "uj"|"megujit"|"bovit", rendeles: "R-…", vevo:{nev, adoszam, cim, kapcsolattarto, email, telefon},
  licenc:{csomag, modulok:[…]|"*", max_felhasznalo, max_peldany, ciklus, lejar, dij}, licenc_kulcs?: "SDH-…"}` →
  válasz `{ok, licenc_kulcs, licenc_id, vevo_id, lejar, letoltes_url (egyszer használható, 14 napig)}`. Ugyanazzal a
  `rendeles` azonosítóval ismételt kérés ugyanazt adja vissza (idempotens, a Központ oldalán tárolva).

## 6. Tartalom (`data/` – a tartalom / jogi / kutató ügynökök írják)
Minden szöveg magyarul, `{termeknev}` tokennel. A bővítmény aktiváláskor / „Tartalom újratöltése” gombra **csak a hiányzó**
elemeket tölti be (a szerkesztettet nem írja felül).
- `data/beallitas-alap.json` – beállítás-kezdőértékek (helyőrzők).
- `data/arak.json` – csomagok, modulok, szolgáltatások, kuponpéldák (séma lent). **Kutató írja** a piackutatás alapján.
- `data/oldalak/<slug>.json` – egy oldal: `{slug, cim, szulo?, sablon?, seo:{title, description}, szekciok:[{tipus, …mezők}]}`.
- `data/funkciok.json`, `data/iparagak.json` – a funkció- / iparági aloldalak adatai (landingek ezekből generálódnak).
- `data/gyik.json` – `[{kulcs, kategoria, kerdes, valasz (HTML-részlet, csak p/ul/li/strong/a), kapcsolodo:[…]}]` (≥ 150).
- `data/blog/<slug>.md` – frontmatter (`cim`, `leiras`, `kategoria`, `cimkek`, `datum`, `kulcsszo`) + Markdown (≥ 20 cikk).
- `data/jogi/<slug>.md` – jogi oldalak (frontmatter `cim`, `verzio`, `hatalyos`), első sor: „TERVEZET – jogásszal ellenőriztetni”.
- `data/oktatas.json` – leckék modulonként, kvízek; `data/telepites.json` – telepítési varázsló lépései, ellenőrzőlista.
- `data/email/<kulcs>.json` – `{targy, html}` tokenekkel (`{nev}`, `{rendeles}`, `{licenc_kulcs}`, `{letoltes_url}`, …).
- `data/tudas/` – Szikra tudásbázisa (építő szkript: `eszkozok/tudas-epit.php`, a Műhely `sugo/`-jából, fejlesztői részek nélkül).

`data/arak.json` séma:
```json
{"csomagok":[{"kulcs":"start","nev":"Start","leiras":"…","havi":0,"eves":0,"felhasznalo_alap":2,"felhasznalo_extra_havi":0,
  "telephely_alap":1,"telephely_extra_havi":0,"modulok":["…"],"kiemelt":false,"cta":"Megveszem","max_peldany":1}],
 "modulok":[{"kulcs":"penztar","nev":"Házipénztár","csoport":"Pénzügy","havi":0,"eves":0,"leiras":"…","csomagban":["pro","business","enterprise"]}],
 "szolgaltatasok":[{"kulcs":"telepites","nev":"Telepítés","tipus":"egyszeri","ar":0,"leiras":"…"},
  {"kulcs":"support_alap","nev":"Support – Alap","tipus":"havi","ar":0,"sla":{"valaszido_ora":24,"munkaido":"H–P 9–17"}}],
 "kuponok":[{"kod":"PROBA10","tipus":"szazalek","ertek":10,"lejar":null,"max":100}]}
```
Minden ár **nettó, egész forint**. A modulkulcsok a Műhely modulkulcsai (`SDH_Kozpont` katalógus: termekek, arukiadas,
rendelesek, penztar, szamlazas, uzenetek, sms, levelezes, csapat, naptar, foglalas, asszisztens, nyomtatas, imei, tac,
matrica, mockup; + a Műhely `Modulok::katalogus()` többi kulcsa) – így a licenc a megvett modulokat engedélyezi.

## 7. Téma ↔ bővítmény
- A téma **csak megjelenít**: az oldal szekcióit a `SDH_Sales_Tartalom::szekciok(int $post_id): array` adja (post meta
  `_sdh_szekciok`, JSON), a téma `szekciok/<tipus>.php` sablonnal rajzolja (`$sz` változóban a szekció adatai).
  Szekciótípusok (a téma mindet tudja): `hero`, `logo_sav`, `jellemzok` (ikon+cím+szöveg rács), `funkcio_racs`, `modul_racs`,
  `iparag_racs`, `arak` (árkártyák), `ar_tablazat` (összehasonlítás), `kalkulator`, `osszehasonlitas` (vs. konkurens),
  `lepesek`, `szoveg` (HTML), `kep_szoveg`, `video`, `velemeny`, `szamok`, `gyik` (kategória / kulcsok szerint), `cta`,
  `urlap` (`kapcsolat`|`ajanlat`|`egyedi`|`partner`), `foglalas`, `demo_inditas`, `blog_lista`, `tudasbazis`, `statusz`,
  `jogi`, `kereso`, `fiok`, `kosar`, `penztar`, `oktatas`, `telepito`. Ismeretlen típus: semmi (és fejlesztőnek HTML-megjegyzés).
- A bővítmény a szekciók **adatait és végpontjait** adja; a téma a markupot és a CSS-t. Interaktív szekciók JS-e
  (kalkulátor, kosár, pénztár, foglalás, űrlapok, kereső, Szikra, süti) a bővítmény `assets/front/*.js`-e, modulonként, csak
  azon az oldalon töltődik, ahol a szekció szerepel (`SDH_Sales_Front::igeny('kalkulator')`). Közös DOM-szerződés:
  `<div data-modul="kalkulator" data-opciok='{…}'></div>` – a JS erre csatol.
- Arculat: a bővítmény a `:root` CSS-változókat írja ki (`SDH_Sales_Arculat::css()`), a téma `assets/css/tokenek.css` adja az
  alapértéket. Változónevek: `--s-bg`, `--s-felulet`, `--s-uveg`, `--s-szoveg`, `--s-halvany`, `--s-vonal`, `--s-kiemelo`,
  `--s-kiemelo-2`, `--s-neon`, `--s-ok`, `--s-figyel`, `--s-hiba`, `--s-r-mezo`, `--s-r-gomb`, `--s-r-pill`, `--s-r-kartya`,
  `--s-arnyek`, `--s-betu-cim`, `--s-betu-szoveg`, `--s-betu-kod`, `--s-max` (tartalomszélesség). Sötét / világos:
  `[data-tema="sotet"|"vilagos"]` a `<html>`-en + `prefers-color-scheme`.
- Menü / lábléc: `SDH_Sales_Tartalom::menu('fo'|'lablec'|'jogi')` → `[{cim, url, gyerekek[]}]` (forrás: `data/menuk.json`
  `[{kulcs:"fo", elemek:[{cim, oldal|url, gyerekek[]}]}]`, tartalom ügynök).
- Kereső: az SEO ügynök építi az indexet (`SDH_Sales_Kereso::index_url()` → `/wp-content/uploads/sdh-sales/kereso-index.json`,
  elemei `{t: cím, u: url, k: "funkcio"|"modul"|"gyik"|"tudas"|"blog"|"oldal"|"iparag", s: rövid kivonat, w: kulcsszavak}`,
  ékezet nélküli `n` mezővel), és a szerveroldali tartalékot (`GET /kereso?q=`). A kereső felületét (Ctrl+K / „/” billentyű,
  elírástűrő keresés a böngészőben) a téma adja (`assets/js/kereso.js`).
- Tartalom-tár API: `SDH_Sales_Tartalom` (fájlfejléc). Funkció- és iparági aloldalak: a tartalom ügynök `data/oldalak/*.json`
  fájlokat ír (a `funkciok/<kulcs>` és `iparagak/<kulcs>` oldalak a `szulo` mezővel), a `data/funkciok.json` és
  `data/iparagak.json` a rácsokhoz (kártyaadatok) és a belső linkhálóhoz kell.

## 8. Cache / harmadik fél
- Nem gyorsítótárazható: `/kosar/`, `/penztar/`, `/fiok/`, `/demo/` (indító), REST. A bővítmény `nocache_headers()` +
  `DONOTCACHEPAGE` + WP Rocket szűrők (`rocket_cache_reject_uri`) + Cloudflare `Cache-Control: private, no-store`.
- Rank Math aktív → a bővítmény nem ír Organization / WebSite / Article / BreadcrumbList sémát (azt a Rank Math adja), csak
  SoftwareApplication, Product+Offer, FAQPage, Event, HowTo-t; Rank Math nélkül mindet.
- Süti-hozzájárulás előtt **semmilyen** harmadik féltől jövő szkript nem fut (Consent Mode v2 alapból `denied`); a WP Consent
  API-t (`wp_has_consent`) is kiszolgáljuk, hogy a Site Kit kövesse.

## 9. Tesztek
- `weboldal/tesztek/_keret.php` (a Műhely `T` állítás-osztálya mintájára), `futtat.php`, tesztek `t2NN-*.php` (200-tól, hogy ne
  ütközzenek a Műhely t22–t93-mal). Egy teszt = egy összegző sor (`t201 ár: 42/42 OK`).
- A hálózat a tesztekben hamis (Számlázz.hu, Barion, SimplePay, Anthropic, Központ távoli): `pre_http_request` szűrővel.
- Tesztoldal: WP 6.8.3 + SQLite. **Minden ügynök a saját másolatán tesztel** (a közös oldalt ne írd):
  `sh weboldal/eszkozok/sajat-teszthely.sh <ugynoknev>` → `/home/claude/env/<ugynoknev>/wp` (a sales és a Központ már
  szimlinkelve és bekapcsolva; témát / demót / Műhelyt a saját másolatodba linkeld és kapcsold be:
  `php /home/claude/env/aktival.php <plugin/fajl.php>` – ez a közös oldalt kapcsolja, a sajátodhoz másold és írd át az útvonalat).
  Futtatás: `SDH_TESZT_ADATBAZIS=1 SDH_WP_LOAD=/home/claude/env/<n>/wp/wp-load.php SDH_TESZT_DB=/home/claude/env/<n>/wp/wp-content/database/sales.sqlite SDH_TESZT_ALAP=/home/claude/env/<n>/alap.sqlite php weboldal/tesztek/futtat.php t2NN`.
  Böngészős próba: `php -S 127.0.0.1:<saját port> -t /home/claude/env/<n>` (siteurl `http://127.0.0.1:8765/wp` – saját portnál
  a `home`/`siteurl` opciót a saját másolatodban írd át). Playwright (Python) és Chromium elérhető.
- Kész példák: `tesztek/t200-mag.php` (mag), `_keret.php`: `T::igaz/egyezik/tartalmaz`, `t_http()` hamis válasz, `t_rest()`.

## 10. Verzió
Mindhárom csomag verziója **3 helyen**: fejléc `Version:`, konstans (`SDH_SALES_VERSION` / `SDH_DEMO_VERSION` / téma
`style.css` + `SDH_TEMA_VERSION`), és a `dokumentacio/README.md` verziónaplója. Első kiadás: **1.0.0**.

## 11. Kiegészítések (menet közben)
- Hook: `sdh_sales_tartalom_mentve` (tipus, kulcs, nyelv) – a tartalom-tár minden mentése / törlése után (kereső-index, llms.txt).
- Tartalomtípus `esemeny` (webinárium → Event séma), szekciótípus `esemenyek` (a téma rajzolja; adat: `lista('esemeny')`).
- Oldal-meta `_sdh_seo_termek` (pl. `csomag:pro`, `modul:penztar`) → Product+Offer séma azon az oldalon.
- `seo.*` beállítások: lásd `class-sdh-sales-seo.php` fejléce (ai_botok, bingbot, sameas, logo, og_kep, localbusiness…).
- GYIK-szekció: a téma `SDH_Sales_Gyik::szekcio($sz)`-t hívja (ez gyűjti a FAQPage-et), a kérdés `id`-je az `anchor`.
  Morzsa: `SDH_Sales_Seo::morzsa()`, kapcsolódó blokk: `SDH_Sales_Seo::kapcsolodo($tipus, $kulcs)`.

## 12. Biztonsági javítások után (2026-10-20, DB 1.0.1) – `dokumentacio/fuggetlen-ellenorzes.md`
- `fizetes` tábla új mezői: `csatorna` (értesítő / kivonat / kapu / kézi), `datum` (a jóváírás napja), `lenyomat` (kanonikus
  ujjlenyomat: dátum + összeg + partner + normalizált közlemény [+ banki hivatkozás] – a forrásokon átívelő duplikáció ellen),
  `parositva` (melyik korábbi sorral azonos). Sor-állapotok: `ok`, `jovahagyasra_var` (név + összeg egyezés, közlemény nélkül –
  automatikus kiszolgálás NINCS, adminnak kell jóváhagynia: `GET /admin/bank/fuggo`, `POST /admin/bank/fizetes/{id}/jovahagy|elutasit`),
  `ellenorizendo`, `duplikat`, `elutasitva`.
- Rendelés-típus és -állapot `ajanlat`: 0 Ft-os / ajánlatkérő tételek (support-ajánlat, egyedi fejlesztés) nem lesznek „fizetve”,
  licenc nem megy – ajánlatkérésként (lead) kezeljük.
- A kiszolgálás kizárólag teljes fizetés után indul (`fizetett ≥ brutto`); részfizetés soha.
- IP: a `CF-Connecting-IP` csak akkor hiteles, ha a kérés a Cloudflare közzétett címtartományából jön (`proxy.cloudflare_ip`),
  más proxy csak a `proxy.megbizhato` listából. Kupon: a fizetetlen rendelés `kupon.foglalas_ora` óráig foglal; `vevonkent` korlát.
- A vevő e-mail-címe / felhasználóneve a rendszer saját REST-jén és profilján át nem írható (fiókátvétel ellen).
- REST: a rendszer saját útvonalai, indexei, `Link`/`X-WP-*` fejlécei látogatónak nem látszanak, a hibaüzenetek magyarok és
  semlegesek; a licencközpont nyilvános útjai (`sdh-kozpont/v1`, `licenc/v1`) `?rest_route=` alakban is elérhetők (a vevők Műhelye).
