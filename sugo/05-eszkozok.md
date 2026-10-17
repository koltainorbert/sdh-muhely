---
kulcs: eszkozok
cim: Eszközök
csoport: Napi munka
modul: eszkozok
szakaszok: imei, mockupok, tac
kulcsszavak: eszköz, készülék, mockup, termékkép, készülékkép, kép, Magnific, prompt, telefon, tablet, laptop, IMEI, gyári szám, sorozatszám, típus, gyártó, TAC, típusfelismerés, IMEI-lekérdezés, zárkód, PIN, feloldó minta, tartozék, garancia, készülékkép, átvételkori állapot, előélet
osszefoglalo: Az Eszközök menüpontban az ügyfelek készülékeit tartod nyilván IMEI-vel, gyári számmal, zárkóddal és átvételi állapottal; a munkalap mindig egy eszközre hivatkozik.
---

## Mire való

Az **Eszközök** menüpont a készülékek nyilvántartása. Egy eszköz egy konkrét készülék, amely egy ügyfélhez tartozik: telefon, tablet, laptop, televízió vagy bármi más, amit javításra hoznak.

A munkalap mindig egy eszközre hivatkozik. Ha ugyanaz a készülék másodszor is bejön, nem kell újra felvenned: az IMEI beírására a rendszer megtalálja, és betölti az adatait.

Az eszköznél tárolod, ami a javításhoz kell: gyári szám, IMEI, sorozatszám, tartozékok, zárkód vagy feloldó minta, garancia és átvételkori állapot.

## Hol találod

- Az oldalmenüben az **Eszközök** pontra kattintva: [Eszközök megnyitása](modul:eszkozok).
- A munkalapon az **Eszköz** mező mellett: pluszjel az új eszközhöz, ceruza a kiválasztott eszköz szerkesztéséhez.
- Az ügyfél adatlapjának alján a **Készülékei** gombbal: ilyenkor a lista csak annak az ügyfélnek a készülékeit mutatja.

Az eszköz adatlapja felugró ablakban nyílik. Az Eszközök lista a legutóbb módosított készülékkel kezdődik, oldalanként 25 sorral.

## Lépésről lépésre: új eszköz felvétele

1. Az Eszközök menüpontban kattints az **Új eszköz** gombra.
2. Az **Ügyfél** mezőbe kezdd el írni az ügyfél nevét vagy telefonszámát, és válaszd ki a listából. Ha még nincs felvéve, a mező melletti pluszjellel helyben felveheted.
3. Kattints a **Kategória** mezőre, és válaszd ki a készülék fajtáját. Az alap a Telefon.
4. Kattints a **Gyártó** mezőre, és válassz. Ha nincs a listában, a felugró ablakban beírhatod a sajátodat.
5. Írd be a **Gyári szám** mezőbe a gyártó modellkódját. Ez azonosítja a készüléket, nem a kereskedelmi név.
6. A **Keresk. név** mezőbe írd azt a nevet, amin az ügyfél ismeri a készüléket.
7. Írd be az **IMEI** számot (15 számjegy) és a **Sorozatszám** mezőt.
8. Válaszd ki a **Szín** és a **Tartozékok** mezőt. A tartozékokból több is bejelölhető, a végén **Kész**.
9. Ha az ügyfél megadta, írd be a **Zárkód / PIN** mezőt, vagy rajzold be a feloldó mintát.
10. Kattints az **Eszköz létrehozása** gombra.

> **Tipp:** Telefonnál kezdd az IMEI-vel. Ha a készülék járt már nálad, minden kitöltődik. Ha nem, a gyártót és a típust a rendszer akkor is felismerheti.

## Lépésről lépésre: IMEI beírása és típusfelismerés

Az eszköz adatlapján az **IMEI** mező a 15. számjegy után magától dolgozik.

1. Írd be vagy olvasd be vonalkódolvasóval az IMEI-t. A mező csak a számjegyeket tartja meg, a szóközt kiszűri.
2. A 15. számjegy után az ablak tetején üzenet jelenik meg. Négyféle lehet:

| Üzenet | Mit jelent |
|---|---|
| „Ez a készülék már szerepel…" | Járt már nálad. Az adatai és az ügyfele betöltődtek, a mentés a meglévő eszközt frissíti. |
| „Új készülék: … – az IMEI alapján." | Még nem járt nálad, de a típusadatbázis felismerte. A gyártó, a gyári szám és a kereskedelmi név kitöltődött. |
| „…a gyári szám nincs az adatbázisban – írd be kézzel." | Csak a gyártót sikerült felismerni. A gyári számot neked kell beírnod. |
| „Ez a készülék még nem járt nálunk – új rekord lesz." | Ismeretlen típus. Töltsd ki kézzel. |

3. Ha elgépelted, ezt látod: „Ez a 15 számjegy nem ad ki érvényes IMEI-t – nézd meg, nem gépelted-e el." Ez csak figyelmeztetés, menteni így is tudsz.

A típusfelismerés az IMEI első nyolc számjegyéből dolgozik. Csak az üres mezőket tölti ki: amit már beírtál, azt nem írja felül. Az adatbázis a rendszerben van, internet nélkül is működik.

## Lépésről lépésre: gyári adatok betöltése ellenőrző oldalról

A sorozatszám, a garancia és a gyártás dátuma nem olvasható ki az IMEI-ből: ezeket a gyártó tudja. Az eszköz adatlapján kétféleképp hozhatod be őket.

### Ingyenes ellenőrző oldallal

1. Írd be a teljes IMEI-t.
2. Az **Ellenőrzés** sorban kattints az egyik ellenőrző oldal gombjára. Az oldal új lapon nyílik meg, az IMEI pedig a vágólapra kerül.
3. Az ellenőrző oldalon illeszd be az IMEI-t, és kérd le az eredményt.
4. Jelöld ki az egész eredményblokkot a címkékkel együtt, és másold ki.
5. Az eszköz adatlapján illeszd be a **Gyári adatok** mezőbe. A feldolgozás rögtön elindul; ha nem, kattints a **Feldolgozás** gombra.
6. Az üzenet megmondja, hány mező töltődött ki.

### Előfizetett szolgáltatóval

Ha a Beállításokban be van kapcsolva az IMEI-lekérdezés, az **Ellenőrzés** sorban megjelenik a **Lekérdezés a szolgáltatótól** gomb. Írd be a teljes IMEI-t, és kattints rá. Siker esetén ezt látod: „Gyári adatok betöltve", a frissült mezők számával.

> **Figyelem:** A beillesztett és a lekérdezett adat a gyártótól származik, ezért a már kitöltött mezőket is felülírja. Mentés előtt nézd át az adatlapot.

## Lépésről lépésre: feloldó minta és zárkód

A zárkód és a feloldó minta az eszköz adatlapjának **Eszköz** lapfülén van.

- A számkódot vagy jelszót a **Zárkód / PIN** mezőbe írd.
- A mintát a jobb oldali **Feloldó minta** rácson rajzolod be.

## A zárkód és a minta védelme

A zárkód és a feloldó minta az ügyfél készülékének kulcsa, ezért a rendszer **titkosítva** tárolja: aki csak az adatbázist vagy egy mentést szerez meg, nem tudja kiolvasni.

- **Mentés után nem látszik magától.** Újra megnyitva az adatlapot a **Zárkód / PIN** mező üres, a helyén ez áll: *•••• mentve – írd felül, ha új*. A mintarács üres, alatta: *Mentett minta van (rejtve)*.
- **Megmutat** – a mező mellett álló gomb előhozza a mentett kódot és mintát. Csak annak a szerepnek jelenik meg, amelyiknél a **Beállítások › Jogosultságok** rácsában be van pipálva a **Feloldó kód és minta megnézése** jog. Minden megnézés bekerül a **Naplóba** (ki, mikor, melyik eszköz).
- **Beírni mindenki tud.** Aki nem nézheti meg, az is beírhat új kódot vagy rajzolhat új mintát – ez felülírja a régit. Ha üresen hagyod, a mentett érték marad. A mintarács **Törlés** gombja a rejtett mintát is törli.
- **Alapból** az Admin, a Kolléga és a Szerelő szerep láthatja; a Pultos és a Pénztáros nem.
- Az Áttekintés részletpaneljén is **•••• rejtve** áll a zárkód és a minta helyén, mellette a **Megmutat** gomb.
- Nyomtatványra (Belső munkalap) és RMA-matricára is csak akkor kerül, ha a nyomtatást indító ember szerepe láthatja.
- Az asszisztens soha nem kapja meg.

A minta berajzolása:

1. Tartsd lenyomva az egérgombot az első pöttyön, és húzd végig, ahogy az ügyfél mutatta.
2. Engedd el az utolsó pöttynél. A vonalon nyilak jelzik az irányt, a kezdőpont gyűrűt kap.
3. A rács alatt megjelenik a sorrend számokkal. A pöttyök számozása balról jobbra, fentről lefelé: 1–9.
4. Ha elrontottad, a **Vissza** gomb az utolsó lépést veszi le, a **Törlés** az egészet.

Húzásnál a két pötty közé eső harmadik is bekerül, ahogy a telefon is teszi. Ha a minta egy pöttyöt többször érint, vagy köztes pöttyöt kihagy, pipáld be a **Szabad rajz** jelölőt: ilyenkor pöttyönként kattintva építed fel.

Egy minta legalább két, legfeljebb húsz lépésből áll.

## Lépésről lépésre: eszköz szerkesztése a munkalapról

1. Nyisd meg a munkalapot.
2. Az **Eszköz** mező mellett kattints a ceruzára. A ceruza csak kiválasztott eszköznél kattintható.
3. Ugyanide jutsz az **Eszköz** lapfülön, az eszköz kártyáján a **Szerkesztés** gombbal. A csatolt további eszközöknek is saját **Szerkesztés** gombjuk van.
4. Az eszköz ablaka a munkalap fölött nyílik. Javítsd, amit kell.
5. Kattints a **Mentés** gombra.

Az eszköz ablaka bezárul, a munkalap nyitva marad, a név és az adatok helyben frissülnek.

Új eszközt is innen vehetsz fel: az **Eszköz** mező melletti pluszjel a kiválasztott ügyféllel előtöltve nyitja az űrlapot, és mentés után az új eszköz ki van választva a lapon. Több készüléket az **Eszköz csatolása** gombbal teszel egy lapra; erről a [Munkalapok](sugo:munkalapok) fejezet ír.

## Lépésről lépésre: készülékkép és csatolt fájlok

1. Nyisd meg az eszköz adatlapját.
2. Az alsó lapfülek közül válaszd a **Készülékkép / fájlok** fület.
3. Felül a készülék képe áll, ha a gyári adatok hoztak képet.
4. Alatta csatolhatsz saját fájlokat: húzd ide őket, vagy kattints a **Fájl hozzáadása** gombra.
5. Kattints a **Mentés** gombra. A fájlok csak ekkor kerülnek fel.

Ide érdemes feltölteni az átvételkor készült fotókat. A lapfülön szám jelzi a csatolt fájlok darabszámát; a fájlonkénti mérethatár a fül alján olvasható.

## Az eszköz előélete

Így nézheted meg, mi történt egy készülékkel korábban:

- **IMEI beírásakor:** ha a készülék már szerepel, az üzenet kiírja a nevét, az ügyfelét és azt, mikor módosították utoljára.
- **Az Eszközök listában:** keress rá az IMEI-re vagy a sorozatszámra.
- **A Munkalapok listában:** a kereső IMEI-re és készülékre is talál. A lista alapból a nyitott lapokat mutatja, a régiekhez állítsd át az állapotszűrőt.
- **Az ügyfél adatlapján:** a **Készülékei** gomb az ügyfél összes eszközét listázza, a **Munkalapok** fül pedig a lapjait, eszközönként megnevezve.

## Mezők és gombok

### Az Eszközök lista oszlopai

| Oszlop | Mit mutat |
|---|---|
| (kép) | A készülék termékképe (mockup) – lásd a Mockupok lapfület |
| Készülék (gyári szám) | Gyártó és gyári szám; alatta halványan a kereskedelmi név és a szín |
| Ügyfél | A tulajdonos; a névre kattintva az ügyfél adatlapja nyílik |
| IMEI / sorozatszám | Az IMEI; ha nincs, a sorozatszám |
| Kategória | A készülék fajtája |
| Garancia | „garanciális" címke, ha a készülék garanciális |

A keresőmező felirata: `Gyári szám, kereskedelmi név, IMEI, sorozatszám, ügyfél…` Gyártóra és a második IMEI-re is keres.

### Az Eszköz lapfül mezői

- **Ügyfél** – kötelező, a listából kell választani.
- **Kategória**, **Gyártó**, **Szín**, **Tartozékok** – kattintásra felugró választó nyílik. A gyártóhoz, a színhez és a tartozékokhoz saját szöveg is beírható.
- **Gyári szám**, **Keresk. név**, **IMEI**, **IMEI 2.**, **Sorozatszám**, **Modellszám**.
- **Zárkód / PIN**, **Feloldó minta**, **Ellenőrzés**, **Gyári adatok**.

### A Garancia és átvétel lapfül mezői

**Garanciális készülék** jelölő, **Vásárlás**, **Garancia lejár**, **Garancia áll.**, **Gyártás**, **Ország**, **SIM-zár**. A dátummezők kattintásra naptárt nyitnak.

### Az alsó lapfülek

- **Megjegyzés** – az ügyfél felé is megjelenhet, például nyomtatványon.
- **Belső megjegyzés** – csak a rendszerben látszik. Az ügyfél nem látja; az ügyfélnek szóló nyomtatványokra nem kerül ki, csak a műhelynek készülő **Belső munkalapra**.
- **Átvételkori állapot** – karcok, törött üveg, hiányzó alkatrész.

## Beállítások: IMEI-lekérdezés lapfül

A Beállítások menüpont *Készülékadatok* csoportjában találod az **IMEI-lekérdezés** lapfület: [Beállítások megnyitása](modul:beallitasok). A Beállításokat csak adminisztrátor nyithatja meg. Itt állítod be, honnan jönnek az eszköz gyári adatai.

### IMEI-szolgáltató doboz

Akkor töltsd ki, ha előfizettél egy IMEI-lekérdező szolgáltatásra.

- **Lekérdezés bekapcsolva** – ha be van pipálva és a végpont meg van adva, az eszköz adatlapján megjelenik a **Lekérdezés a szolgáltatótól** gomb.
- **Végpont címe** – a szolgáltató dokumentációjából másold ide. Az IMEI helyére írd: `{imei}`, a kulcs helyére: `{kulcs}`. A rendszer behelyettesíti őket.
- **API-kulcs** – a szolgáltatótól kapott kulcs. A rendszer a kérés fejlécében is elküldi.

### Ingyenes ellenőrző oldalak doboz

Az **Oldalak** mezőben soronként egy ellenőrző oldal áll, ebben a formában: `Név | URL`. Ezekből lesznek az eszköz adatlapján az **Ellenőrzés** sor gombjai.

- A gomb megnyitja az oldalt, és az IMEI-t a vágólapra teszi.
- Ha egy oldal a címében is fogadja az IMEI-t, írd a címbe az `{imei}` jelölőt: akkor már kitöltve nyílik meg.
- Üresen hagyva az alapértelmezett lista jön vissza.

A lapfül alján a **Mentés** gombbal mentesz; ez csak ennek a lapfülnek az adatait menti.

## Mockupok: termékkép minden eszköz mellett

Minden eszköz mellett termékkép (mockup) áll: a munkalap **Eszközök a lapon** paneljén és **Eszköz** lapfülén, az Áttekintés részletpanelén és az **Eszközök** listában. A kép a készülék **kategóriájából** adódik (telefon, tablet, laptop, okosóra…). A rendszer 20 gyári, márkajel nélküli termékfotóval érkezik, egységes stúdióstílusban.

Melyik kép jelenik meg:

1. Ha egy mockupnál megadtál **gyártót** vagy **típusrészletet**, és az eszköz illik rá, az nyer (például gyártó *Apple*, típus *iPhone* → saját iPhone-kép).
2. Különben az eszköz saját fényképe, ha van (IMEI-lekérdezésből vagy feltöltve).
3. Különben a kategória mockupja.
4. Ha egyik sincs: a tartalék kép (az *Egyéb eszköz* – amelyiknek se kategóriája, se szabálya nincs).

## Beállítások: Mockupok lapfül

A Beállítások *Készülékadatok* csoportjában a **Mockupok** lapfülön szerkeszted a termékképeket. Csak adminisztrátor látja.

### Generáló szöveg (prompt) sablonja doboz

A **Sablon** mező adja az egységes stílust (fehér háttér, stúdiófény, márkajel nélkül). A `{eszkoz}` helyére kerül a mockup leírása. Üresen hagyva a gyári sablon áll vissza.

### Mockupok doboz

Minden mockup egy sor:

| Mező | Mire való |
|---|---|
| **Kép** | Az előnézet alatt a kép címe. **Feltöltés…** – kép a gépről; **Kép törlése** – kép nélkül a sor nem jelenik meg az eszközöknél. Ide beilleszthetsz külső képcímet is (például a Magnificből kimásolt linket): mentéskor a rendszer letölti a saját képei közé, így akkor is megmarad, ha a külső link lejár. |
| **Név** | A mockup neve, például *Telefon*. |
| **Gyártó (nem kötelező)** | Csak ennek a gyártónak az eszközeire illik. |
| **Típus tartalmazza (nem kötelező)** | Vesszővel több részlet is megadható, például *iPhone, Galaxy S*. |
| **Kategóriák** | Mely kategóriák eszközeinél jelenjen meg. Ctrl / Cmd lenyomva több is választható. |
| **Leírás a generáló szöveghez (angolul)** | A kép tárgya angolul, például *modern smartphone with thin bezels*. |
| **Generáló szöveg (prompt)** | Lenyitva a kész szöveg. **Másolás** – a vágólapra; **Megnyitás a Magnificben** – új lapon nyílik. |
| **×** | A sor törlése (két kattintás: *Biztosan?*). A mentéskor kerül le. |

Az **Új mockup** gombbal új sor kerül a lista végére. A **Gyári készlet visszaállítása mentéskor** pipával a lista helyére a gyári 20 kép kerül vissza (a feltöltött képek megmaradnak).

### Lépésről lépésre: új mockup a Magnific segítségével

1. A Mockupok lapfülön kattints az **Új mockup** gombra, és írd be a **Nevet**, a **Kategóriát** (vagy a gyártót / típust) és az angol **Leírást**.
2. Nyisd le a **Generáló szöveget**, és kattints a **Másolás** gombra.
3. A **Megnyitás a Magnificben** gombbal nyisd meg a Magnificet, és generálj képet a szöveggel.
4. A kész képen használd a háttér eltávolítását (*Remove background*), hogy átlátszó legyen.
5. Töltsd le, és a sorban a **Feltöltés…** gombbal töltsd fel – vagy másold be a kép címét a képmezőbe.
6. Kattints a **Mentés** gombra.

## Beállítások: TAC adatbázis lapfül

A Beállítások *Készülékadatok* csoportjának másik lapfüle a **TAC adatbázis**. Ez a típusfelismerés háttere: az IMEI első nyolc számjegyéből mondja meg a gyártót és a típust.

### Állapot doboz

- **Betöltött készüléktípus** – hány típust ismer most a rendszer.
- **Saját felvitelből tanult** – ebből hányat tanult a saját eszközfelvitelekből.
- **Forrásfájl** – megvan-e a rendszerrel szállított típuslista.

A **Betöltés indítása** gomb tölti be a szállított listát. Ha már volt betöltés, a gomb felirata **Újratöltés**. A betöltés darabokban fut, és eltart egy percig; közben a gomb mellett látod, hol tart. Ismételt futtatás nem hoz létre duplikátumot.

### Honnan jön az adat doboz

A szállított lista egy nyilvános típusadatbázisból készült, és 2025 végéig tart. A típusok nagyjából felénél van meg a gyári szám; a többinél csak a gyártó és a kereskedelmi név.

Az újabb készülékeket a rendszer magától tanulja meg: amikor egy ismeretlen típust kézzel felveszel IMEI-vel, gyártóval és gyári számmal, bekerül az adatbázisba, és legközelebb magától kitöltődik. A saját bejegyzéseidet az újratöltés nem írja felül.

## Szabályok, amiket a rendszer betart

- **Eszköz ügyfél nélkül nem menthető.** Az ügyfelet a listából kell kiválasztani, a gépelés önmagában nem elég.
- **Egy készülék egyszer szerepel.** Ha a beírt IMEI már megvan, a mentés a meglévő eszközt frissíti, nem készít másodikat. A mentés gomb felirata ilyenkor **Mentés** lesz.
- A belső megjegyzés soha nem kerül az ügyfél elé.
- A munkalapon csak a kiválasztott ügyfél eszközei választhatók. Ha szerkesztés közben az eszközt másik ügyfélhez teszed, lekerül a munkalapról.
- Az eszköznek nincs törlés gombja: a felvett készülék a nyilvántartásban marad.
- A listában és a fejlécben a gyártó és a gyári szám áll. Ha nincs gyári szám, a kereskedelmi név lép a helyére; ha az sincs, „Névtelen eszköz".

## Tippek

- A jobb alsó sarokban élő asszisztensnek írd be: „Vezess végig ezen az oldalon". Többlépcsős túrát indít az Eszközök oldalon.
- A teljes dokumentációt és a gyakori kérdéseket a **Súgó** menüpontban találod.
- Az **Átvételkori állapot** fület mindig töltsd ki. Egy mondat a karcokról megvéd a későbbi vitától.
- A zárkódot és a mintát átvételkor kérd el: enélkül a javítás nehezen tesztelhető.
- A munkalap **Eszköz** lapfülén az IMEI, a sorozatszám és a típus mellett másoló gomb áll.

## Ha valami nem megy

- **„Válassz ügyfelet a listából – gépelés önmagában nem elég."** Kattints a lenyíló listában az ügyfélre, vagy vedd fel a pluszjellel.
- **Az IMEI beírására nem történik semmi.** Számold meg a számjegyeket: pontosan 15 kell. A keresés ennél rövidebb számra nem indul el.
- **Nem ismeri fel a típust.** A készülék újabb, mint a típuslista, vagy a lista nincs betöltve. Töltsd ki kézzel: mentés után a rendszer megjegyzi. Az adminisztrátor a Beállítások **TAC adatbázis** lapfülén ellenőrizheti a betöltést.
- **„Ebből nem tudtam adatot kiolvasni."** Az egész eredményblokkot másold ki, a címkékkel együtt, ne csak az értékeket.
- **Nincs „Lekérdezés a szolgáltatótól" gomb.** Nincs bekapcsolva a Beállítások **IMEI-lekérdezés** lapfülén.
- **A ceruza szürke a munkalapon.** Nincs kiválasztott eszköz. Előbb válassz ügyfelet, utána eszközt.

## GYIK

### Honnan jön a kép az eszköz mellett?

A Beállítások **Mockupok** lapfüléről: a rendszer a készülék kategóriája (vagy egy gyártó / típus szabály) szerint választ termékképet. Ha az eszköznek saját fényképe van, a kategóriaképnél az áll előrébb.

### Hogyan cserélem le egy kategória képét?

Beállítások › **Mockupok**: a kategória sorában **Feltöltés…**, majd **Mentés**. Ugyanilyen stílusú képhez másold ki a sor generáló szövegét, és készítsd el a Magnificben.

### Miért nem látszik kép egy eszköz mellett?

Nincs a kategóriájához mockup, és tartalék kép sincs (törölted az *Egyéb eszköz* sort vagy a képét). Vegyél fel egy mockupot kategória nélkül, vagy állítsd vissza a gyári készletet.

### Hogyan veszek fel új készüléket?

Az Eszközök menüpontban kattints az **Új eszköz** gombra, válaszd ki az ügyfelet, töltsd ki a készülék adatait, majd **Eszköz létrehozása**. Munkalap írása közben az **Eszköz** mező melletti pluszjel ugyanezt teszi.

### Mi a különbség a gyári szám és a kereskedelmi név között?

A gyári szám a gyártó modellkódja: ez azonosítja egyértelműen a készüléket, ebből rendelsz alkatrészt. A kereskedelmi név az, amin az ügyfél ismeri. A listában a gyári szám áll elöl.

### Mi történik, ha olyan IMEI-t írok be, ami már szerepel?

A rendszer betölti a meglévő eszköz adatait és az ügyfelét, és kiírja, mikor módosították utoljára. A mentés ezt az eszközt frissíti, nem jön létre másodpéldány.

### Honnan tudja a rendszer a típust az IMEI-ből?

Az IMEI első nyolc számjegye a készüléktípust azonosítja. A rendszerben van egy típusadatbázis, abból keresi ki a gyártót és a gyári számot. Internet nem kell hozzá.

### Miért nem ismeri fel az új telefonokat?

A szállított típuslista 2025 végéig tart. Az ennél újabb készüléket egyszer kézzel kell felvenned; utána a rendszer megjegyzi, és legközelebb magától kitölti.

### Azt írja, hogy az IMEI nem érvényes. Menthetem?

Igen. Ez csak figyelmeztetés az elgépelésre. Nézd meg még egyszer a számot; ha biztosan jó, mentsd nyugodtan.

### Mit írjak be, ha a készüléknek nincs IMEI-je?

Hagyd üresen az **IMEI** mezőt, és töltsd ki a **Sorozatszám** mezőt. A listában ilyenkor a sorozatszám jelenik meg, és keresni is lehet rá.

### Kétkártyás telefonnál hová kerül a második IMEI?

Az **IMEI 2.** mezőbe. A rendszer a második IMEI alapján is megtalálja a készüléket.

### Hogyan rögzítem a feloldó mintát?

Az eszköz adatlapján a **Feloldó minta** rácson húzd végig az egeret, ahogy az ügyfél mutatta. A nyilak az irányt jelzik, alatta számokkal is látod a sorrendet.

### A minta kétszer megy át ugyanazon a pöttyön. Hogyan rajzolom be?

Pipáld be a **Szabad rajz** jelölőt, és pöttyönként kattintva építsd fel a mintát. Így egy pötty többször is érinthető, és a köztes pötty kihagyható.

### Hol látom a zárkódot, amikor a készüléken dolgozom?

Az eszköz adatlapján és az Áttekintés részletpaneljén is rejtve áll (**•••• rejtve**): nyomd meg a **Megmutat** gombot. Ha nincs ilyen gomb, a szereped nem nézheti meg – szólj az adminisztrátornak (**Beállítások › Jogosultságok**, **Feloldó kód és minta megnézése**).

### Miért nem látom a zárkódot, amit tegnap beírtam?

Mert titkosítva tárolódik, és csak kérésre jelenik meg: nyomd meg a **Megmutat** gombot a mező mellett. A megnézés a Naplóba kerül.

### Látja az ügyfél a belső megjegyzést?

Nem. A **Belső megjegyzés** csak a rendszerben látszik; az ügyfélnek szóló nyomtatványra és üzenetbe nem kerül. Egyedül a műhelynek készülő **Belső munkalapon** szerepel (a zárkóddal együtt). A sima **Megjegyzés** megjelenhet az ügyfél felé is.

### Át tudom tenni az eszközt másik ügyfélhez?

Igen. Nyisd meg az eszközt, az **Ügyfél** mezőben válassz másik ügyfelet, és mentsd. Ha az eszköz épp egy nyitott munkalapon van, onnan lekerül, mert más ügyfél készüléke nem maradhat a lapon.

### Hogyan törlök egy tévedésből felvett eszközt?

Az eszköznek nincs törlés gombja, a felvett készülék a nyilvántartásban marad. Ha csak elírtad, nyisd meg, javítsd ki az adatait, és mentsd.

### A beillesztett gyári adat felülírja, amit már beírtam?

Igen. A gyártótól származó adat erősebb, ezért a kitöltött mezőket is felülírja. Az IMEI-ből történő típusfelismerés viszont csak az üres mezőkbe ír.

### Hogyan nézem meg, hányszor járt nálunk egy készülék?

Írd be az IMEI-t a Munkalapok keresőjébe, és az állapotszűrővel a lezárt lapokat is kérd le. Az ügyfél adatlapjának **Munkalapok** fülén is látod a lapokat, a készülék nevével.

### Egy ügyfél három készüléket hozott. Három munkalap kell?

Nem. Egy munkalapra több eszköz is felvehető az **Eszköz csatolása** gombbal. Mindegyiket külön kell felvenni az Eszközök közé, de egy lapon mennek.

## Túra: eszkozok

- `.sdh-fejlec__gombok [data-sdh-urlap="eszkozok"]` | Új eszköz | Új készülék felvétele felugró ablakban. Az ügyfelet a listából kell kiválasztani.
- `.sdh-kereso input[name="k"]` | Keresés | Gyári számra, kereskedelmi névre, IMEI-re, sorozatszámra és az ügyfél nevére is kereshetsz.
- `.sdh-kereso__talalat` | Találatok száma | Megmutatja, hány eszköz felel meg a keresésnek vagy a szűrésnek.
- `.sdh-tabla thead` | A lista oszlopai | Készülék, ügyfél, IMEI vagy sorozatszám, kategória és garancia. Elöl a legutóbb módosított eszköz áll.
- `.sdh-tabla tbody .sdh-tabla__nev a` | A készülék | A gyártó és a gyári szám. Kattintásra megnyílik az adatlap az IMEI-vel, a zárkóddal és a feloldó mintával.
- `.sdh-tabla tbody [data-sdh-urlap="ugyfelek"]` | A tulajdonos | Az ügyfél nevére kattintva az ő adatlapja nyílik meg, ugyanitt, felugró ablakban.
- `.sdh-tabla tbody td:last-child .sdh-gomb` | Megnyit | Ugyanazt nyitja, mint a készülék neve: az eszköz adatlapját szerkesztésre.
- `.sdh-lapozo` | Lapozó | Egy oldalon 25 eszköz fér el. A többit a számokra kattintva éred el.
