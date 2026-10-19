# Versenytárs-elemzés – {termeknev}

Készült: 2026-10-19 (kutató ügynök), élő oldalakról (WebFetch / WebSearch). Gépi adat: `sales/sdh-sales/data/konkurensek.json`.

**Árfolyam:** MNB hivatalos középárfolyam, 2026-10-16 (péntek): **1 EUR = 363,29 Ft, 1 USD = 323,07 Ft**
(forrás: https://www.mnb.hu/arfolyamok, lekérve 2026-10-19). A külföldi árak áfa / sales tax nélküliek → nettó Ft-ban
számolva, egészre kerekítve. A magyar áraknál jelölöm, ha a forrás bruttót közöl (nettó = bruttó ÷ 1,27, számított).
Amit nem tudtam forrásból igazolni, az „ismeretlen” / „nem közölt”.

## 1. Összehasonlító táblázat (havi, nettó Ft)

| Termék | Belépő csomag | Középső | Felső | Felhasználó a belépőben | Próba | Magyar felület | Számlázz.hu | Saját szerver |
|---|---|---|---|---|---|---|---|---|
| RepairDesk | Essential 31 984 (havi) / 25 523 (éves) / telephely | Growth 48 137 / 38 445 | Advanced: egyedi | 5 / telephely | van (hossza nem közölt) | nem (EN, FR) | nem | nem |
| RepairShopr | Starter 22 612 / 19 381 | Repair Shop 45 227 / 41 996 | Big Chain 48 457 / 45 227 | 1 (75 jegy/hó) | nem közölt | nem (EN) | nem (QuickBooks) | nem |
| Orderry | Hobby 12 600 | Startup 22 292-től, Business 31 984-től | Enterprise 64 291-től | 2 (Hobby), 3 (többi) | 7 nap, kártya nélkül | nem (EN, FR, ES, PT) | nem | nem |
| Fixably | nem közölt | – | – | – | nincs (G2) | nem közölt | nem | nem |
| mHelpDesk | nem közölt (csak ajánlat) | – | – | – | harmadik fél szerint van | nem közölt | nem | nem |
| Syncro (MSP) | gyártói oldal nem elérhető; harmadik fél: 41 676 / technikus (éves) | – | – | per technikus | harmadik fél: 14 nap | nem közölt | nem | nem |
| CellSmart POS | 14 538-tól | egyedi összeállítás | – | nem közölt | nem említett | nem (EN, ES) | nem | nem |
| MunkalapTár | FREE 0; START 3 142 (3 990 bruttó) | BASIC 4 717 (5 990 br.) | PROF 7 079 (8 990 br.) | 2 (START) | ingyenes csomag | igen | **igen** | nem |
| Szerviz Programom | 7 500 / eszköz | – | – | eszközönként | igen | igen | nem (saját számlázó) | nem |
| Innonest | Smarty 3 490-től + 790/modul | miniÜGYVITEL 8 990 | ÜGYVITEL 24 990 | 3 | 14 nap | igen | **igen** | nem |
| HUNiDOC | Basic 0; Professional 5 000 | Premium 17 500 (éves) | Gold: egyedi | 2 / 5 / 10 | 15 nap | igen | nem említett | nem |
| wSoft Workshop | ≈ 1 509 (22 990 bruttó / év, korlátlan gép) | – | – | jogosultságok | demóverzió | igen | export, fizetős modul | **igen** (Firebird / MySQL) |
| NATURASOFT K&Sz Pro | 5 742 (68 900 / év) | – | – | nem közölt | nem közölt | igen | nem (saját számlázó) | nem közölt |
| ServiceLeaf (CMMS) | 9 900 | 24 900 | 59 900; Vállalati egyedi | 5 | 14 nap | igen | nem említett | csak Vállalati |
| MiniCRM | csak ajánlat | – | – | – | van | igen | nem közölt | nem |

**Megfigyelés (mérésből):** a nemzetközi javítópult-szoftverek belépő ára 12 600–31 984 Ft/hó/telephely, a középső
csomagok 22 000–48 000 Ft. A magyar munkalap-programok olcsók (0–9 000 Ft/hó), de általánosak (terepi szerviz, ügyvitel),
és egyikük sem kínál telefonszerviz-specifikus funkciókat (IMEI/TAC, RMA-matrica). → A {termeknev} a kettő közé
pozicionálható (lásd `arazas.md`).

## 2. Termékenként

### RepairDesk (https://www.repairdesk.co/pricing/)
- **Csomagok:** Essential 99 USD havi / 79 USD éves (−20%), Growth 149 / 119, Advanced egyedi; telephelyenként.
  Felhasználó/telephely: 5 / 8 / korlátlan; jegy: korlátlan.
- **Funkciók:** munkafolyamat-automatizálás, több telephely, riportok, önkiszolgáló bejelentkezés, javításkövető widget,
  beszámítás (buyback), belső wiki. Fizetős kiegészítők: e-mail-kampány, Appointments Pro, telefonrendszer, Mail-in Suite.
  AI: „ARIA” telefonos recepciós (munkaidőn kívüli hívások, időpontfoglalás) – blog, 2026-06-12.
- **Próba / demó / onboarding:** ingyenes próba (hossza nem közölt), demó űrlappal; onboarding: 1. nap fiókmenedzser,
  2–6. nap migráció, 7. nap élesítés; **ingyenes adatátvétel** (blog).
- **GYIK-témák:** csomagválasztás, kezdés, integrációk csomagonként, havi opció, éves kedvezmény, csomagváltás, visszatérítés,
  fizetési módok (Stripe, PayPal, átutalás).
- **SEO / bizalom:** „#1 Repair Shop POS” (forrás nélkül), ügyféltörténetek, iparági aloldalak, irodák (Wilmington, Lahore),
  partnerprogram, GDPR-oldal; összehasonlító blogcikkek („RepairDesk vs RepairShopr in 2026”).

### RepairShopr (https://www.repairshopr.com/pricing)
- **Csomagok:** Starter 69,99 havi / 59,99 éves (1 fő, 1 telephely, 75 jegy+számla/hó), Repair Shop 139,99 / 129,99
  (10 fő, korlátlan), Big Chain 149,99 / 139,99 (2+ telephely).
- **Minden csomagban:** e-mail, terepi munka, riportok, árajánlat, ügyfélportál, marketing, SMS-keret, POS, munkaidő-óra,
  QuickBooks, pénztárgépek.
- **Próba:** „Try it now” regisztráció (hossza nem közölt). GYIK nincs az árlapon. Bizalom: biztonsági oldal, **státuszoldal**.

### Orderry (https://orderry.com/pricing/)
- **Csomagok:** Hobby 39 USD (2 fő, 1 telephely, 100 munkalap/30 nap, nem bővíthető), Startup 69-től (3 fő, +6 USD/fő,
  +49 USD/telephely, max 15 fő), Business 99-től (+9 / +79, max 150), Enterprise 199-től (+19 / +179, max 1500).
  Éves számlázás: −10%.
- **Próba:** 7 nap, minden funkció, kártya és szerződés nélkül; a maradék próbanapok vásárláskor megmaradnak.
- **Onboarding:** Startup 1 × 45 perc, Business 2 alkalom + személyre szabott, Enterprise korlátlan. Adatimport: Startupban
  nincs, Businessben korlátozott, Enterprise-ban korlátlan. „Done-for-you” beállítás felárért.
- **Biztonság:** 2FA, IP-fehérlista (fizetős csomagokban), 2 óránkénti mentés (3-2-1), titkosítás, adatok az USA-ban.
- **GYIK-témák:** próba, Hobby-korlátok, csomagváltás arányosítással, fizetési módok (kártya, SWIFT/SEPA), lejárat után
  7 nap türelmi idő, **adattörlés 1 év után**, automatikus megújítás, „telephely” fogalma, egyidejű munkamenetek,
  ajánlói program (10%).
- **Nyelv:** angol, francia, spanyol, portugál (súgócikk) – **magyar nincs**.

### Fixably (https://www.fixably.com/)
- Márkaszervizekre (Apple GSX, Samsung), depókra és felújítókra. Ár nem nyilvános (G2), próba nincs (G2), demó foglalható.
- **Bizalom:** sok ügyféllogó (iStyle, Compnow, iDoc), számszerű esettanulmányok („közel 2000 technikusóra 4 hónap alatt”,
  „+40% technikus-hatékonyság”), DPA és EU Data Act-függelék.

### mHelpDesk (https://www.mhelpdesk.com/)
- Terepi szervizekre. Árat nem közöl („Get a Quote”). A gyártói oldalt nem értem el; harmadik fél (serviceagent.ai,
  2026-10) ~99–169 USD/fő/hó és 500–5000 USD bevezetési díjat ír – **nem ellenőrzött, ellentmondásos**.

### Syncro (https://syncrosecure.com/pricing/ – a lekérés engedély hiányában elmaradt)
- IT-szolgáltatóknak (RMM + PSA), nem javítópult. Harmadik fél (eesel.ai, 2026-08): Core 129 / 159 USD/technikus/hó,
  Team 179 / 209, 14 napos próba. Közvetlen konkurenciának nem tekintem.

### CellSmart POS (https://www.cellsmartpos.com/)
- Telefonboltoknak: POS + javításkövetés SMS-sel + IMEI-ellenőrző + hűségprogram. „As low as 45 USD/hó”, szerződés nélkül,
  a fel nem használt hónapok visszatérítése. Demó + „Build & Price” konfigurátor. Bizalom: Google 4,5/5, „50 millió tranzakció”,
  Capterra/GetApp-jelvények 2024.

### Magyar munkalap- és ügyviteli programok
- **MunkalapTár** (https://www.munkalaptar.hu/): 0 / 3 990 / 5 990 / 8 990 Ft bruttó/hó, heti munkalapkorláttal; Számlázz.hu,
  SMS (SeeMe), aláírható PDF, garancia-nyilvántartás. Bizalom: 4,8 (19 szavazat), státuszoldal. Egyéni fejlesztő.
- **Szerviz Programom** (https://www.szervizprogramom.hu/): 7 500 Ft + áfa/hó/eszköz; terepi szerviz; saját számlázó NAV-
  bekötéssel; interaktív demó mintaadattal. Bizalom: „20 év KKV-tapasztalat”, magyar ügyfélszolgálat.
- **Innonest** (https://innonest.hu/arak/): 3 490 / 8 990 / 24 990 Ft + áfa/hó, modulonként 790 Ft; éves ár = ~10 havi;
  14 napos próba; Számlázz.hu-partner; ügyfélportál, raktár, ticket.
- **HUNiDOC** (https://hunidoc.com/): 0 / 5 000 / 17 500 Ft + áfa, extra fő 1 400 Ft; 15 nap próba; mobilapp offline módban;
  PassKey; „fix árak, nincs rejtett költség”.
- **wSoft Workshop** (https://www.wsoft.hu/programok/szerviz-program.html): Windows-program, 22 990 Ft bruttó/év; internet
  nélkül; saját Firebird/MySQL szerver; Számlázz.hu-export fizetős modul. Bizalom: 4,7 (69 szavazat), verziónapló 2013 óta,
  aláírt binárisok.
- **NATURASOFT** (https://www.naturasoft.hu/szerviz-munkalap.php): munkalap a Készlet és Számla Pro-ban, 5 742 Ft + áfa/hó
  vagy 68 900 Ft/év; jótállási jegy; telefonos support.
- **ServiceLeaf** (https://serviceleaf.hu/arak): CMMS (karbantartás), 9 900 / 24 900 / 59 900 Ft/hó; 14 nap próba; 15 GYIK-kérdés
  az árlapon; mintadokumentumok a láblécben.
- **MiniCRM** (https://www.minicrm.hu/arak/): általános KKV-CRM, csak egyedi ajánlat.
- Nem lekérdezhető (robots / engedély): onlinemunkalap.hu, szervizx.hu.

## 3. Ahol a {termeknev} erősebb (a Műhely README / súgó alapján, csak létező funkció)
Objektív összevetés: az alábbiak egyikét sem találtam meg együtt egyetlen vizsgált terméknél sem.
- **Telefonszerviz-specifikus:** IMEI-lekérdezés, TAC-adatbázis (gyártó/típus felismerés), Brother QL RMA-matrica,
  okoskeresés IMEI / telefonszám / sorozatszám szerint. (Magyar versenytársnál nem láttam; CellSmart-nál IMEI-ellenőrző van.)
- **Magyar nyelv + Számlázz.hu + magyar pénztár** egyszerre – a nemzetközi termékeknél egyik sincs.
- **Saját szerveren fut, offline is működik** – a felhős nemzetközi termékeknél nincs; magyarban csak a wSoft (asztali program).
- **Adatbiztonság:** TOTP kétlépcsős belépés, titkosított külső mentés (Drive/FTP/NAS) + visszaállítás, GDPR-anonimizálás,
  mezőszintű napló, szerepkörök, közös gépen „ki vagy?” PIN, párhuzamos szerkesztés zárolása.
- **SMS a bolti androidos telefonról** – nincs havidíjas SMS-kapu (RepairShopr/Orderry SMS-kerettel dolgozik).
- **Ügyfélkapcsolat:** RMA-ügyféloldal QR-kóddal, online árajánlat-elfogadás, elégedettségi kérdőív Google-értékelés-gombbal,
  online időpontfoglalás.
- **AI-asszisztens (Claude)** a felületen, jóváhagyásos módosítással (RepairDesk AI-ja telefonos recepciós, más).
- **MunkaLap 3 / CSV / XLSX adatátvétel**, 300 000 rekordos teljesítménymérés, egykattintásos frissítés, teszt mód.
- **Gyengébb pontjaink (őszintén):** nincs natív mobilapp (böngészőben, telefonra igazítva működik – 0.96.0); nincs integrált
  kártyás POS-terminál; nincs nyilvános ügyfélszám / értékelés még; angol felület nincs.

## 4. Amit átveszünk (forrással)
1. **Havi / éves kapcsoló az árlapon, éves kedvezmény %-ban kiírva** – RepairDesk (−20%), Orderry (−10%).
   https://www.repairdesk.co/pricing/ · https://orderry.com/pricing/
2. **„Hány felhasználó / telephely van benne” sor minden árkártyán + extra fő és extra telephely ára** – Orderry (+6 USD/fő,
   +49 USD/telephely), HUNiDOC (+1 400 Ft/fő). https://orderry.com/pricing/ · https://hunidoc.com/
3. **Próba kártya nélkül, a maradék napok vásárláskor megmaradnak** – Orderry. (Nálunk: demó 10 000 rekorddal.)
4. **Onboarding csomagonként leírva (hány alkalom, mennyi idő)** – Orderry; **„7 nap alatt élesben” idővonal** – RepairDesk blog.
   https://blog.repairdesk.co/2026/06/12/repairdesk-vs-repairshopr-in-2026-whats-changed-and-what-hasnt/
5. **Árazási GYIK** a csomagok alatt: csomagváltás, lemondás, adatok sorsa lejárat után, fizetési módok – Orderry, RepairDesk.
6. **„Fix ár, nincs rejtett költség” ígéret + mi NEM része az árnak** (Számlázz.hu-előfizetés, IMEI-szolgáltató, AI-díj) – HUNiDOC,
   ServiceLeaf. https://serviceleaf.hu/arak
7. **Nyilvános státuszoldal** – RepairShopr, MunkalapTár.
8. **Számszerű esettanulmányok és ügyféllogók** – Fixably. (Első referencia: SDH Szerviz, ha Norbi engedi.)
9. **Interaktív demó mintaadattal** – Szerviz Programom. (Nálunk már tervezve.)
10. **Verziónapló nyilvánosan** – wSoft (2013 óta). (A Műhely README-ből szűrt, látogatóbarát változat.)
11. **Összehasonlító oldalak / blogcikkek** („X vs Y 2026”) – RepairDesk blog → a mi `/osszehasonlitas` oldalunk + 20. blogcikk.
12. **Ajánlói program** – Orderry (a megújítás 10%-a).

## Források (lekérve 2026-10-19)
https://www.mnb.hu/arfolyamok · https://www.repairdesk.co/pricing/ · https://blog.repairdesk.co/2026/06/12/repairdesk-vs-repairshopr-in-2026-whats-changed-and-what-hasnt/ ·
https://www.repairshopr.com/pricing · https://orderry.com/pricing/ · https://help.orderry.com/en/articles/9130058-how-to-change-the-interface-language-in-orderry ·
https://www.fixably.com/ · https://www.g2.com/products/fixably/pricing · https://serviceagent.ai/blogs/mhelpdesk-pricing/ ·
https://www.eesel.ai/blog/syncro-secure-pricing · https://www.cellsmartpos.com/pricing · https://www.cellsmartpos.com/ ·
https://www.munkalaptar.hu/ · https://www.szervizprogramom.hu/ · https://innonest.hu/arak/ · https://hunidoc.com/ ·
https://www.wsoft.hu/programok/szerviz-program.html · https://www.naturasoft.hu/szerviz-munkalap.php · https://serviceleaf.hu/arak ·
https://www.minicrm.hu/arak/
