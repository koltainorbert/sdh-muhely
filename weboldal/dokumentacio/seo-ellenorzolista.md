# SEO-ellenőrzőlista – {termeknev} értékesítő webhely

Mérhető pontok élesítés előtt és havonta. Minden sor: **mit**, **mivel mérjük**, **cél**. Ez a lista nem ígér
helyezést: a rangsort a Google dönti el. Azt méri, hogy a webhely technikailag rendben van és a tartalom elérhető.
A kód: `sales/sdh-sales/includes/class-sdh-sales-seo.php`, `-gyik.php`, `-kereso.php`, `-llms.php`; tesztek: `t300–t309`.

## 0. Gyors parancsok
| Mit | Parancs / hely |
|---|---|
| Automata tesztek | `php weboldal/tesztek/futtat.php t30` → 10 sor, mind „OK” |
| robots.txt | `curl -s https://<domain>/robots.txt` |
| llms.txt | `curl -sI https://<domain>/llms.txt` (200, `text/plain; charset=utf-8`) |
| Kereső-index | `curl -s https://<domain>/wp-content/uploads/sdh-sales/kereso-index.json \| head -c 300` |
| JSON-LD egy oldalon | `curl -s <url> \| grep -o '<script type="application/ld+json">[^<]*' \| sed 's/.*json">//' \| python3 -m json.tool` |
| Szerveroldali kereső | `curl -s 'https://<domain>/wp-json/sdh-sales/v1/kereso?q=munklap'` |

## 1. Technikai alapok
| # | Ellenőrzés | Mérés | Cél |
|---|---|---|---|
| T1 | Minden oldal HTTPS, HTTP → HTTPS 301 | `curl -sI http://<domain>/` | 301, `Location: https://…` |
| T2 | Egy kanonikus host (www vagy www nélkül) | `curl -sI` mindkettőre | a másik 301-gyel átirányít |
| T3 | Egy `<link rel="canonical">` oldalanként | forrás: `grep -c 'rel="canonical"'` | pontosan 1 |
| T4 | Egy `<title>` és egy `meta description` | forrás | 1–1; title ≤ 60, leírás 120–160 karakter |
| T5 | Egy H1 oldalanként | böngésző DevTools: `document.querySelectorAll('h1').length` | 1 |
| T6 | Noindex a segédoldalakon | `/kosar/`, `/penztar/`, `/fiok/`, `/kereses/`, `/?s=x`, `/?s=x&paged=2` forrása | `noindex, follow` mindegyiken |
| T7 | Indexelhető a többi oldal | Search Console → Oldalak → „Nincs indexelve” okai | nincs „noindex” ok kereskedelmi oldalon |
| T8 | robots.txt tiltások | `curl robots.txt` | `Disallow: /kosar/ /penztar/ /fiok/ /wp-json/sdh-sales/` a `*` csoportban |
| T9 | Egy `Sitemap:` sor | `curl robots.txt \| grep -c Sitemap` | 1 (Rank Math-tal `sitemap_index.xml`, nélküle `wp-sitemap.xml`) |
| T10 | Oldaltérkép tartalma | a sitemap XML-ben az URL-ek száma | minden közzétett oldal + cikk; **nincs** benne kosár/pénztár/fiók/keresés, szerzői archívum |
| T11 | 404 helyesen | `curl -sI https://<domain>/nincs-ilyen-oldal` | `404` (nem 200, nem 302 a főoldalra) |
| T12 | Átirányítási láncok | Screaming Frog (ingyenes, ≤ 500 URL) → Redirect Chains | 0 lánc, 0 hurok |
| T13 | Törött belső link | Screaming Frog → Response Codes → Client Error (4xx) | 0 |
| T14 | `X-Robots-Tag: noindex` a REST-en | `curl -sI …/wp-json/sdh-sales/v1/kereso?q=ab` | megvan, `Cache-Control: private, no-store` |
| T15 | hreflang (csak több nyelvnél) | forrás, `nyelvek` beállítás > 1 | `hu`, `en`, …, `x-default`, oda-vissza hivatkoznak (lásd 8.) |

## 2. Strukturált adat (JSON-LD)
Eszközök: **Google Rich Results Test** (https://search.google.com/test/rich-results) és **Schema.org Validator**
(https://validator.schema.org/). Mindkettőn oldaltípusonként egy URL-t kell lefuttatni, élesítés előtt és minden
sablon- vagy Rank Math-módosítás után.

| # | Oldal | Várt típusok Rank Math **nélkül** | Rank Math **mellett** (a mi kimenetünk) | Cél |
|---|---|---|---|---|
| S1 | Főoldal | Organization, WebSite (+SearchAction), SoftwareApplication (AggregateOffer), [LocalBusiness, ha `seo.localbusiness`] | SoftwareApplication | 0 hiba, 0 duplikált típus |
| S2 | `/arak/` | Organization, WebSite, SoftwareApplication, BreadcrumbList | SoftwareApplication (+ BreadcrumbList, ha a RM-morzsa ki) | 0 hiba |
| S3 | Csomag- / modul-oldal | + Product (Offer, UnitPriceSpecification) | Product | Rich Results: „Termékkivonatok” érvényes |
| S4 | Oldal GYIK-szekcióval | + FAQPage (csak a látható kérdések) | FAQPage | a FAQPage kérdései = az oldalon látható kérdések (darabra) |
| S5 | `/telepites/` | + HowTo | HowTo | lépésszám = a telepítési lépések száma |
| S6 | Esemény / webinár oldal | + Event | Event | `startDate` ISO 8601 időzónával, `location`, `organizer` megvan |
| S7 | Blogcikk | + BlogPosting, BreadcrumbList | (a Rank Math adja) | `headline`, `datePublished`, `author`, `publisher` megvan |

További szabályok (ellenőrizd a Schema.org Validatorban):
- A Rank Math mellett **egy** Organization és **egy** WebSite van az oldalon (a Rank Math-é); a mi csomópontjaink `@id`-vel hivatkoznak rá (`/#organization`).
- Az árak nettóban, `valueAddedTaxIncluded: false` (vagy bruttóban `true`, ha `seo.ar_brutto` be van kapcsolva) – egyezzenek az árak oldallal.
- FAQPage: a Google 2023 óta csak kormányzati / egészségügyi oldalaknál mutat GYIK-kiemelést; a séma ettől még segít az AI-válaszoknak. Ne várj tőle kiemelt találatot.
- Search Console → **Fejlesztések** (Termékkivonatok, Morzsa, Események): 0 „Érvénytelen elem”.

## 3. Core Web Vitals (CWV)
Mérés: **PageSpeed Insights** (https://pagespeed.web.dev/) mobil, és Search Console → **Core Web Vitals** (valós
felhasználói adat, 28 napos). A labor-szám tájékoztató; a döntő a terepadat (CrUX), ha van elég forgalom.

| # | Mutató | Cél (75. percentilis, mobil) |
|---|---|---|
| C1 | LCP (Largest Contentful Paint) | ≤ 2,5 s |
| C2 | INP (Interaction to Next Paint) | ≤ 200 ms |
| C3 | CLS (Cumulative Layout Shift) | ≤ 0,1 |
| C4 | TTFB (oldal-gyorsítótárral) | ≤ 0,8 s |
| C5 | Kereső-index mérete | gzip után ≤ 50 KB (mért: lásd 9.) – csak a kereső megnyitásakor töltődik |
| C6 | JS a fronton (kereső nélkül) | csak a szekciók modulja töltődik (`SDH_Sales_Front::igeny`) – DevTools → Network → JS |
| C7 | Lighthouse SEO-pontszám | 100 (a főoldal, árak, egy funkció, egy cikk) |
| C8 | Lighthouse Akadálymentesség | ≥ 95 (WCAG 2.2 AA a cél) |

## 4. Belső linkháló
Mérés: Screaming Frog → Internal → „Inlinks” oszlop, vagy Search Console → Linkek → Belső linkek.

| # | Ellenőrzés | Cél |
|---|---|---|
| L1 | Minden funkció-landing bejövő belső linkje | ≥ 3 (funkciórács + „Kapcsolódó” blokk + legalább egy blogcikk) |
| L2 | Minden iparági landing | ≥ 2 bejövő; kimenő linkek a saját funkcióira (`iparagak.json` `funkciok`) |
| L3 | Minden blogcikk | ≥ 1 kimenő link a céloldalára (kulcsszókutatás 4. fejezet) + 1 CTA (demó / árak) |
| L4 | Árak oldal | ≥ 10 bejövő (menü, lábléc, CTA-k) |
| L5 | Árva oldal (0 bejövő belső link) | 0 db (a jogi oldalak a láblécből kapnak linket) |
| L6 | Kattintási mélység a főoldaltól | minden kereskedelmi oldal ≤ 3 kattintás |
| L7 | „Kapcsolódó” blokk | minden funkció-, iparág- és blogoldalon 3–6 link (`SDH_Sales_Seo::kapcsolodo()`) |
| L8 | GYIK-horgonyok | `/gyik/#gyik-<kulcs>` mind létező `id`-re mutat (kereső-index `gyik` elemei) |

## 5. Tartalom és kulcsszavak
| # | Ellenőrzés | Mérés | Cél |
|---|---|---|---|
| K1 | Egy oldal = egy fő kulcsszó (kutatás 3. fejezet) | title + H1 + első 100 szó + slug | mind a négyben szerepel |
| K2 | Nincs két oldal ugyanarra a fő kulcsszóra | Search Console → Teljesítmény → Lekérdezés → Oldalak | egy lekérdezésre egy fő oldal kap megjelenést |
| K3 | Title / description egyedi | Screaming Frog → Page Titles / Meta Description → Duplicate | 0 |
| K4 | `{termeknev}` token nem maradt a kimenetben | `curl -s <url> \| grep -c '{termeknev}'` minden sablonon | 0 |
| K5 | Képek `alt` szövege | Screaming Frog → Images → Missing Alt Text | 0 (dekorációs kép: üres `alt=""`) |
| K6 | Csak létező funkció szerepel | szúrópróba a Műhely README / súgó alapján | 0 nem létező funkció |

## 6. Kereső (a webhely saját keresője)
| # | Ellenőrzés | Mérés | Cél |
|---|---|---|---|
| Q1 | Index friss | szerkessz egy GYIK-et → ≤ 5 perc múlva (cron) az index `v` hash-e változik | változik |
| Q2 | Elírástűrés | `GET /kereso?q=munklap`, `?q=szamlazas`, `?q=telefonszervz` | az első találat a Munkalapok / Számlázás / Telefonszerviz oldal |
| Q3 | Válaszidő | a válasz `ms` mezője | ≤ 50 ms (szerveroldali tartalék) |
| Q4 | Rate limit | 61 kérés / perc egy IP-ről | a 61. → 429 |
| Q5 | WP-keresés (`/?s=`) | `/?s=munklap` | a tartalom-tár találatai (funkció-landing) is megjelennek |
| Q6 | Üres keresések | (ha a téma naplózza) a 0 találatos kifejezések listája havonta | új GYIK / szinonima a gyakoriakra |

## 7. AI-láthatóság (ChatGPT, Claude, Perplexity, Gemini, Copilot)
Ezeket nem lehet garantálni, csak az elérhetőséget mérni. Havonta egyszer, ugyanazokkal a kérdésekkel, az eredményt
dátummal rögzítve (táblázat: kérdés, eszköz, említ-e, hivatkozik-e a webhelyre, helyes-e az ár / funkció).

| # | Ellenőrzés | Mérés | Cél |
|---|---|---|---|
| A1 | AI-botok beengedése | `curl robots.txt` → „# AI-keresők” csoport | `seo.ai_botok` = igaz esetén `Allow: /` a GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-User, Claude-SearchBot, anthropic-ai, PerplexityBot, Perplexity-User, Google-Extended, Applebot-Extended, CCBot, Bingbot, msnbot számára |
| A2 | Tűzfal / Cloudflare nem blokkolja őket | Cloudflare → Security → Bots → „AI Scrapers and Crawlers” beállítás; Security Events szűrve a bot nevére | nincs blokk, ha az A1 engedélyez |
| A3 | `llms.txt` elérhető | `curl -sI /llms.txt` és `/llms-full.txt` | 200, `text/plain; charset=utf-8`, ETag; az llms.txt ≤ 50 KB |
| A4 | `llms.txt` formátum (llmstxt.org) | első sor `# {termeknev}`, utána `> ` összefoglaló, `## Funkciók … ## Optional` linklisták | megfelel |
| A5 | `llms-full.txt` árai | összevetés az árak oldallal | minden csomag nettó + bruttó ára egyezik |
| A6 | Szerveroldali HTML | `curl -s <url>` (JS nélkül) tartalmazza a fő szöveget, árakat, GYIK-et | igen – a botok többsége nem futtat JS-t |
| A7 | Kérdezés-próba | ChatGPT (keresés bekapcsolva), Perplexity, Claude (webkeresés), Gemini, Copilot: „Milyen szerviz programot ajánlasz telefonszerviznek Magyarországon?”, „Mennyibe kerül a {termeknev}?”, „Melyik szerviz program tud IMEI-keresést és Számlázz.hu-számlát?” | rögzítve: említi-e, linkel-e, helyes-e – **nincs célérték** |
| A8 | Bing Webmaster Tools | webhely hozzáadva, oldaltérkép beküldve (a Copilot a Bing indexéből dolgozik); IndexNow opcionális | az oldaltérkép „Sikeres” |
| A9 | Hivatkozó forgalom AI-ból | analitika: hivatkozók `chatgpt.com`, `perplexity.ai`, `claude.ai`, `gemini.google.com`, `copilot.microsoft.com` | havonta rögzítve (trend, nincs cél) |

Ha az AI-botokat ki kell zárni: admin → SEO → `seo.ai_botok` ki. A Bingbot tiltása a Bing-keresőből is kivenné a
webhelyet, ezért az külön kapcsoló (`seo.ai_botok_bing`); tiltott AI-botoknál a Bingnek `noarchive` meta jelzi,
hogy a tartalmat ne használja a Copilot-válaszokban. A felhasználó által indított lekérőket (ChatGPT-User, Perplexity-User)
a gyártók szerint a robots.txt nem mindig köti – a tényleges tiltás a Cloudflare / WAF dolga.

## 8. Többnyelvűség (később: /en/, /de/)
Szerkezet (kódban kész, a `nyelvek` beállítás > 1 elemnél kapcsol be): a magyar a gyökérben (`/arak/`), a többi
nyelv előtaggal ugyanazon az úton (`/en/arak/`, `/de/arak/`); minden oldal kiírja a `hreflang` párokat és az
`x-default`-ot (= magyar). A fordított oldalak létrehozása (WPML / Polylang vagy saját `nyelv` mező a tartalom-tárban)
külön döntés. Ellenőrzés: minden nyelvi változat visszahivatkozik a többire (oda-vissza), a kanonikus a saját nyelvi URL.

## 9. Mért értékek (2026-10-19, tesztoldal, WP 6.8.3 + SQLite, PHP 8.3)
| Mit | Mért érték |
|---|---|
| Kereső-index, mintaadat (29 elem) | 4 999 B, gzip 1 607 B, építés ≈ 30 ms |
| Kereső-index, valós GYIK (175 kérdés) + minta nélkül (178 elem) | 67 344 B, gzip 19 532 B, építés 36,7 ms |
| Szerveroldali keresés, 178 elem | átlag 10,4 ms, max 12,2 ms / lekérdezés |
| Szerveroldali keresés, 200 elemes szintetikus index | 4,3–4,6 ms / lekérdezés |
| llms.txt / llms-full.txt (valós GYIK, funkciók és árak nélkül) | 5 867 B / 78 782 B |
A teljes tartalommal (funkciók, iparágak, oldalak, blog) újramérendő: `php weboldal/tesztek/futtat.php t309` kiírja.

## 10. Search Console és Bing – beállítás (Norbi)
1. Search Console: **Domain-tulajdon** (DNS TXT rekord) – lefedi a http/https és www változatokat.
2. Oldaltérkép beküldése: Rank Math-tal `/sitemap_index.xml`, nélküle `/wp-sitemap.xml`.
3. **URL-ellenőrzés** a főoldalra, az árakra és egy funkció-oldalra → „Élő URL tesztelése” → a megjelenített HTML-ben látszik a tartalom és a JSON-LD.
4. Bing Webmaster Tools: importálás Search Console-ból; oldaltérkép.
5. Havonta: Teljesítmény (megjelenés, kattintás, CTR, átlagos pozíció) a 3. fejezet fő kulcsszavaira – trend, nem ígéret.
6. Riasztás: Search Console e-mail-értesítés bekapcsolva (indexelési / biztonsági probléma).

## 11. Élesítés előtti zárólista
- [ ] `t300–t309` mind zöld
- [ ] Beállítások: `ceg.*` kitöltve, `seo.logo`, `seo.og_kep` (1200×630), `seo.sameas` (Facebook, LinkedIn, YouTube…), `seo.ai_botok` döntés
- [ ] WordPress → Beállítások → Olvasás: „Keresőmotorok ne indexeljék” **kikapcsolva** (különben a robots.txt mindent tilt)
- [ ] Rank Math (ha használod): Séma → Organization adatai kitöltve; a morzsa beállítása egyezik a téma morzsájával
- [ ] S1–S7 a Rich Results Testen 0 hibával
- [ ] T1–T15, C1–C3 (PageSpeed mobil), A1–A6 kipipálva
- [ ] Search Console + Bing beállítva, oldaltérkép beküldve
