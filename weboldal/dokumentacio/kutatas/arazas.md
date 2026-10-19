# Árazási javaslat – {termeknev}

Készült: 2026-10-19 (kutató ügynök). **Kitalált, piachoz igazított javaslat** – élesítés előtt Norbi dönt.
Gépi adat: `sales/sdh-sales/data/arak.json` (séma: ARCHITEKTURA.md §6). Minden ár **nettó, egész forint**;
áfa (27%) a beállításból adódik hozzá. **Éves = havi × 12 − 15%** (egész Ft-ra kerekítve; `eves_kedvezmeny` = 15).

## 1. Piaci sáv (mérés, `konkurencia.md` – MNB 2026-10-16: 1 USD = 323,07 Ft)
| Szegmens | Belépő (Ft/hó nettó) | Középső | Felső |
|---|---|---|---|
| Nemzetközi javítópult (RepairDesk, RepairShopr, Orderry) | 12 600 – 31 984 | 22 292 – 48 137 | 45 227 – 64 291+ |
| Magyar munkalap / ügyvitel (MunkalapTár, Innonest, HUNiDOC, Szerviz Programom, NATURASOFT) | 0 – 7 500 | 4 717 – 17 500 | 7 079 – 24 990 |

**Pozicionálás (vélemény):** a magyar általános munkalap-programok fölé (szakosodott telefon- és elektronikai szerviz funkciók,
saját szerver, offline, Számlázz.hu, AI), de a nemzetközi termékek alá – a magyar szerviz árérzékeny, és a nemzetközi
termékek nem tudnak magyarul / Számlázz.hu-t.

## 2. Csomagok
| Csomag | Havi | Éves | Felhasználó (alap / extra havi) | Telephely (alap / extra havi) | Modulok | Példány |
|---|---|---|---|---|---|---|
| Start | **9 900** | 100 980 | 2 / 1 900 | 1 / 4 900 | alap + Ügyfélüzenetek/RMA, Nyomtatványok, TAC, Mockup | 1 |
| Pro (kiemelt) | **19 900** | 202 980 | 5 / 1 900 | 1 / 4 900 | + Termékek, Árukiadás, Rendelések, Pénztár, Számlázz.hu, SMS, Naptár, Foglalás, IMEI, Brother-címke (14 modul) | 1 |
| Business | **34 900** | 355 980 | 10 / 1 500 | 2 / 3 900 | minden modul (`*`), AI-asszisztens, Levelezés, Csapat | 2 |
| Enterprise | **69 900-tól** (ajánlatkérés) | 712 980-tól | 25 / 1 200 | 5 / 2 900 | minden modul, egyedi feltételek | 5 |

Indoklás:
- **Start 9 900:** a MunkalapTár PROF (7 079 nettó) és a ServiceLeaf Alap (9 900) szintje – de már telefonszerviz-funkciókkal.
- **Pro 19 900:** az Orderry Hobby (12 600) és Startup (22 292) között; a RepairDesk Essential (25 523 éves) alatt 22%-kal.
- **Business 34 900:** a RepairDesk Essential havi (31 984) és Growth éves (38 445) között, több felhasználóval és 2 telephellyel.
- **Enterprise 69 900-tól:** az Orderry Enterprise (64 291-től) szintje; mindig egyedi ajánlat (`ajanlatkeres: true`, `ar_tol: true`).
- Extra felhasználó 1 200–1 900 Ft (HUNiDOC: 1 400 Ft; Orderry: 1 938–6 138 Ft); extra telephely 2 900–4 900 Ft
  (Orderry: 15 830–57 830 Ft – mi szándékosan olcsóbbak vagyunk, mert a saját szerveres üzem nem drágít).

## 3. Modulok (külön is megvehetők annak a csomagnak, amelyben nincsenek benne)
Kulcsok = a Műhely `SDH_Muhely_Modulok::katalogus()` és `SDH_Kozpont::KATALOGUS_ALAP` 17 kulcsa (a kettő megegyezik).
Kihagyva a belső SDH-modulok (`includes/sdh/modulok.php`: SDH, Gigatel, 3D kísérő) – ezek a vevői csomagban nincsenek.
Az alapfunkciók (munkalap, ügyfél, eszköz, szolgáltatás, mentés, napló, riport, súgó) nem modulok, minden csomagban benne vannak.

| Kulcs | Név | Havi | Éves | Csomagban |
|---|---|---|---|---|
| termekek | Termékek és készlet | 2 990 | 30 498 | pro, business, enterprise |
| arukiadas | Napi árukiadás | 1 990 | 20 298 | pro+ |
| rendelesek | Rendelések (igényli: uzenetek) | 1 990 | 20 298 | pro+ |
| penztar | Házipénztár | 1 990 | 20 298 | pro+ |
| szamlazas | Számlázz.hu számlázás | 2 490 | 25 398 | pro+ |
| uzenetek | Ügyfélüzenetek és RMA-oldal | 1 990 | 20 298 | start+ |
| sms | SMS-értesítések (igényli: uzenetek) | 1 990 | 20 298 | pro+ |
| levelezes | Levelezés | 2 490 | 25 398 | business+ |
| csapat | Csapat-üzenetek | 990 | 10 098 | business+ |
| naptar | Naptár | 990 | 10 098 | pro+ |
| foglalas | Online időpontfoglalás (igényli: naptar) | 1 990 | 20 298 | pro+ |
| asszisztens | AI-asszisztens | 3 990 | 40 698 | business+ |
| nyomtatas | Nyomtatványok | 990 | 10 098 | start+ |
| imei | IMEI-lekérdezés | 1 490 | 15 198 | pro+ |
| tac | TAC-adatbázis | 990 | 10 098 | start+ |
| matrica | Címkenyomtatás (Brother) | 1 490 | 15 198 | pro+ |
| mockup | Eszköz-mockupok | 490 | 4 998 | start+ |

Ellenőrzés (számolva az arak.json-ból): Start + a Pro 10 plusz modulja külön = 9 900 + 19 400 = 29 300 Ft > Pro 19 900 →
a csomag megéri (szándékos). Az összes modul havi összege 31 330 Ft.
A modulárak nem tartalmazzák a külső szolgáltatók díját (Számlázz.hu-előfizetés, IMEI-szolgáltató, Claude API, mobil-SMS) –
ezt a leírás kiírja („mi NEM része az árnak” – HUNiDOC/ServiceLeaf mintája).

## 4. Szolgáltatások
| Kulcs | Név | Típus | Ár | SLA |
|---|---|---|---|---|
| telepites | Első telepítés | egyszeri | 39 900 | – |
| telepites_telephely | Telepítés új telephelyre | egyszeri | 24 900 | – |
| oktatas | Oktatás (óra) | egyszeri, óradíj | 12 900 / óra | – |
| support_alap | Support – Alap | havi | 0 (licenc része) | 24 óra, H–P 9–17 |
| support_pro | Support – Pro | havi | 9 900 | 8 óra, H–P 8–18 |
| support_premium | Support – Prémium | havi | 29 900 | 2 óra, H–P 8–20, Szo 9–13 |
| hibajegy_5 / _10 / _20 | Hibajegy-csomag | egyszeri | 24 900 / 44 900 / 79 900 | 12 hónapig, ≤ 30 perc / jegy |
| modulfejlesztes | Egyedi modulfejlesztés | egyszeri, óradíj | 14 900 / óra | – |
| egyedi_fejlesztes | Egyedi szoftverfejlesztés | ajánlatkérés | 0 (`ajanlatkeres: true`) | – |
| munkalap3_atvetel | MunkaLap 3 adatátvétel | egyszeri | 49 900 | – |

Megjegyzés: a RepairDesk ingyen adja az adatátvételt (blog, 2026-06), az Orderry csak a felső csomagokban. Javaslat:
a `VALTAS20` kupon a váltóknak ezt részben ellensúlyozza; alternatíva: Business / Enterprise csomagban ingyenes (Norbi döntése).
A „valaszido_ora” és munkaidő **kitalált vállalás** – csak akkor élesíthető, ha Norbi kapacitása ezt bírja.

## 5. Kuponok
| Kód | Típus | Érték | Lejár | Max felhasználás |
|---|---|---|---|---|
| PROBA10 | százalék | 10 | – | 100 |
| VALTAS20 | százalék | 20 | 2027-06-30 | 50 (MunkaLap 3-ról / Excelből váltóknak) |
| INDULO15 | százalék | 15 | 2027-03-31 | 30 (induló kampány) |

## 6. Nyitott kérdések (Norbi)
1. Az árszint jóváhagyása (különösen Start 9 900 vs. a 3–5 ezres magyar munkalap-programok).
2. Az SLA-válaszidők vállalhatók-e egy emberrel.
3. MunkaLap 3 átvétel: fizetős (49 900) vagy a nagyobb csomagokban ingyenes.
4. `max_peldany` (1 / 1 / 2 / 5) – a Központ licenc `max_peldany` mezőjébe megy.
