---
kulcs: munkalapok
cim: Munkalapok
csoport: Napi munka
modul: munkalapok
szakaszok: allapotok, hiba-allapotok, gyakori-hibak, gyakori-megjegyzesek, szamozas, zarolas
kulcsszavak: munkalap, munkalapok, javítás, szerviz, felvétel, átvétel, új munkalap, állapot, munkalapszám, sorszám, számozás, hiba, hibák, gyakori hibák, tétel, szolgáltatás, termék, több eszköz, részben elkészült, bevizsgálási díj, előleg, fizetett, fizetve, fizetendő, megjegyzés, gyakori megjegyzések, címke, QR-kód, számla, érvénytelen, törlés, árajánlat, sablon, lezárt, zárolt, újranyitás, nem szerkeszthető, csak olvasható, napló, előzmény, matrica
osszefoglalo: A munkalap egy javítási ügy teljes története – ügyfél, eszköz, hibák, szolgáltatások, termékek, fizetés – a felvételtől a lezárásig.
---

## Mire való

A **munkalap** egy javítási ügy: kié a készülék, mi a baja, mit csináltatok vele, mennyibe kerül, és hol tart. Egy munkalap egy ügyfélhez tartozik, és egy vagy több eszközről szól.

A munkalap végigkíséri a javítást: felvétel, állapotváltások, hibák, tételek, előleg, fizetés, számla, lezárás. Az ügyfél a címkén lévő QR-kóddal maga is követheti.

A pultos eladás (napi árukiadás) és a rendelés ugyanilyen lap, csak eszköz, hibák és bevizsgálási díj nélkül – ezeknek saját menüpontjuk van.

## Hol találod

- Az oldalmenüben: [Munkalapok](modul:munkalapok) – a javítási lapok listája.
- Az [Áttekintés](modul:attekintes) rácsában: ott minden munkalap látszik, szűrhető és rendezhető. Leírása: [Áttekintés](sugo:attekintes).
- Új lap: a **+ Új munkalap** gomb az Áttekintés és a Munkalapok oldal fejlécében is ott van.

A munkalap mindig popupban nyílik, az oldal elhagyása nélkül.

## Lépésről lépésre: új munkalap felvétele

1. Kattints a **+ Új munkalap** gombra. Megnyílik az *Új munkalap* ablak.
2. Az **Ügyfél** mezőbe kezdd el írni a nevet, a telefonszámot vagy az ügyfélszámot, és válassz a listából. Ha még nincs felvéve, a mező melletti **+** gombbal veheted fel.
3. Az **Eszköz** mezőben válaszd ki az ügyfél készülékét. Új készüléket a mellette lévő **+** gombbal vehetsz fel.
4. A **Hibák** lapfülön írd be a hibát, vagy válassz a **Gyakori hibák** közül.
5. Ha kell, állíts **Határidőt**, és válassz **Felelőst** (alapból te vagy az).
6. Ha az ügyfél bevizsgálási díjat vagy előleget fizet, rögzítsd a bal oldali **Fizetés** részben.
7. Kattints a **Munkalap létrehozása** gombra.

Mentés után a rendszer kiírja a kiosztott számot: *A munkalap létrejött. Száma: …* – ezt írd a készülék tasakjára, vagy nyomtass címkét.

> **Példa:** Minta Márta behozza a telefonját törött kijelzővel. Ügyfél: Minta Márta, eszköz: a telefonja, hiba: *Törött kijelző*, állapot: *Nyitott*. Mentés után a lap megkapja a következő munkalapszámot.

## Mezők és gombok

A munkalap ablakának bal oldalán a lap adatai, jobb oldalán az ügyfél, az eszköz és a lapfülek állnak.

| Rész | Mezők |
|---|---|
| **Állapot** | **Állapot** (több eszköznél **Közös állapot**), **Név** – rövid tárgy, nem kötelező –, **Felelős**. |
| **Dátumok** | **Készült** (alapból a mai nap), **Határidő**, és lezárt lapnál a **Lezárva** nap. |
| **Összeg** | **Nettó**, **Áfa**, **Bruttó** – a tételekből számolódik, gépelés közben frissül. |
| **Tételek** | **Kedvezmény** (%) és **Áfakulcs** – minden tételsorra érvényes, az új sorok is ezzel indulnak. |
| **Fizetés** | **Fizetési mód**, **Fizetve**, **Fizetés ideje**, **Fizetett (előleg)**, **Bevizsgálási díj**, alul a **Fizetendő**. |

Az ablak alján:

- **Munkalap létrehozása** (új lapnál) vagy **Mentés**, és **Mégsem**.
- **Fizetés** – mentett lapnál; kifizetett lapon a felirata **Fizetve ✓**.
- **Számlázz.hu számla** és **Számla-előkészítő (PDF)** – csak olyan lapnál, amelynek már van munkalapszáma.
- **Matrica** – a készülékre ragasztható RMA-matrica Brother címkenyomtatóra (munkalapszámmal rendelkező javítási lapnál). Lásd: [RMA-matrica](sugo:matrica).
- **Napló** – a lap teljes története: ki, mikor, mit módosított. Lásd: [Napló](sugo:naplo).

## Ügyfél és eszköz a munkalapon

A munkalap jobb felső részén áll az **Ügyfél** és az **Eszköz** mező. Mindkettő mellett két kis gomb van:

- **+** – új ügyfél, illetve új eszköz felvétele. Az ablak a munkalap *fölött* nyílik; mentés után a munkalap megmarad, és az új ügyfél vagy eszköz már ki is van választva.
- **Ceruza** – a kiválasztott ügyfél vagy eszköz adatainak szerkesztése, szintén a lap fölött. Mentés után a név és az adatok a lapon helyben frissülnek.

Tudnivalók:

- Az ügyfelet a listából kell kiválasztani – a begépelt név önmagában nem elég.
- Az eszközlista csak a kiválasztott ügyfél készülékeit mutatja. Előbb ügyfelet válassz.
- Az **Eszköz** lapfülön minden eszköz saját adatlapot kap, a termékképével: azonosító, gyártó, típus, szín, garancia, IMEI szám, sorozatszám, tartozékok. A második, harmadik eszköz is ugyanilyen teljes adatlapot kap. Alattuk az ügyfél adatai (név, cím, telefonszám, e-mail). Minden adat mellett másoló gomb van, a kártyák fejlécében **Szerkesztés**.

## Állapotok és a munkalapszám

A munkalap állapota mutatja, hol tart az ügy. Az alaplista:

| Állapot | Kap számot? | Tudnivaló |
|---|---|---|
| **Bejelentett** | igen | Nyitottnak számít. |
| **Árajánlat** | nem | Még nem munkalap; ügyfél és eszköz nélkül is menthető. |
| **Sablon** | nem | Ugyanúgy, mint az árajánlat. |
| **Függő** | igen | Nyitottnak számít. |
| **Nyitott** | igen | Az új munkalap ezzel indul. |
| **Elkészült** | igen | Az eszköz kész, átvehető. |
| **Részben elkészült** | igen | Több eszköznél: az egyik kész, a másik még nem. Magától áll be. |
| **Lezárt** | igen | Lezárt, már nem számít nyitottnak. |
| **Érvénytelen** | igen | Lezártnak számít; a tévedésből felvett lap helye. |

A lista a Beállításokban átírható (lásd: *Állapotok*).

**Munkalapszám:** a lap akkor kap számot, amikor először lép *számozott* állapotba. Ehhez ügyfél és eszköz kell. A kiosztott szám végleges: a lap később sem kap másikat. A munkalap, a napi árukiadás és a rendelés közös számsorból kap számot.

**Állapotváltás:**

- Már mentett lapon az új állapot **azonnal mentődik** – a mező alatt megjelenik: *Állapot elmentve*. A popup nyitva marad.
- Új, még nem mentett lapon az állapot a többi mezővel együtt, a létrehozáskor mentődik.
- Az állapot a Munkalapok listában és az Áttekintés rácsában, a sorban is átállítható.
- Lezárt állapotba lépve beíródik a **Lezárva** nap. Ha a lapot újra megnyitod (nem lezárt állapotba teszed), a dátum törlődik.

## A munkalap lapfülei

A munkalap jobb oldalán öt lapfül van:

| Lapfül | Mire való |
|---|---|
| **Eszköz** | Az eszköz és az ügyfél adatai kártyán. |
| **Hibák** | A hibasorok: mi a baj, hol tart, mit csináltatok. |
| **Szolgáltatások** | Munkadíjak, javítások. |
| **Termékek** | Alkatrészek, kiegészítők. |
| **RMA** | Címke és QR-kód, ügyféloldal, üzenetek az ügyféllel, állapottörténet. |

A lapfülek nevén szám mutatja, hány hiba, illetve tétel van rajta.

Alul két további lapfül: **Megjegyzés** és **Belső megjegyzés**. Felkiáltójel jelzi, ha van bennük szöveg.

## Hibák és gyakori hibák

A munkalap **Hibák** lapfülén minden hiba egy sor: **Hiba**, **Állapot**, **Javítás / megjegyzés**. Több eszköznél a sor elején azt is megadod, melyik **Eszköz** hibája.

- Új sor: a **+ Hiba** gomb.
- Sor törlése: a sor végi **×**.
- Minden hibának saját állapota van (alapból: **Új**, **Folyamatban**, **Kész**, **Nem javítható**).
- Az üresen hagyott sor mentéskor eldobódik.

### Lépésről lépésre: hiba felvétele a gyakori hibákból

1. A Hibák lapfülön kattints a **Gyakori hibák** gombra.
2. Több eszköznél fent válaszd ki, **melyik eszköz hibája**.
3. Kattints a hibákra – mindegyik rögtön felkerül a munkalapra. Egymás után többet is választhatsz; ami már a lapon van, kiemelve látszik.
4. Ha a hiba nincs a listán, írd be alul a **Más hiba – írd be…** mezőbe, és kattints a **Felvétel** gombra. Ha az **A gyakori hibák közé is** pipa be van jelölve, a hiba a listára is felkerül, és legközelebb onnan választható.
5. Kattints a **Kész** gombra.

## Tételek: szolgáltatások és termékek

A munkalap **Szolgáltatások** és **Termékek** lapfülén állnak a tételek. Ezekből adódik a lap összege.

### Lépésről lépésre: szolgáltatás felvétele

1. A Szolgáltatások lapfülön kattints a **+ Szolgáltatás** gombra. Megnyílik a *Szolgáltatás választása* ablak.
2. Keress a megnevezésben, majd kattints a sorra (vagy [[↑]] [[↓]] és [[Enter]]).
3. A tétel a munkalapra kerül a megnevezéssel, a mennyiségi egységgel és az árral.

A választó ablakban:

- **+ Új szolgáltatás** – felveszel egyet a szolgáltatások közé, és rögtön választható.
- **Ceruza** a sor végén – a szolgáltatás szerkesztése.
- **Kézzel írom be** – üres sort ad a munkalapon, a nevet és az árat te írod be.

A terméknél ugyanígy megy: **+ Termék** gomb, *Termék választása* ablak. Ott megnevezésre, cikkszámra, termékkódra és vonalkódra is kereshetsz, és látod a készletet.

### A tételsor mezői

**Megnevezés**, **Menny.**, **M.e.** (mennyiségi egység), **Bruttó ár**, **Kedv. %**, **Áfak.**, majd a kiszámolt **Nettó é.** és **Bruttó é.** A termékeknél ezen felül: **Termékkód**, **Cikkszám**, **Gyári szám**.

- Az ár mindig **bruttó egységár**. A nettót a rendszer számolja az áfakulcsból.
- Az áfakulcs alapból 27%. Soronként átírható; a bal oldali **Áfakulcs** minden sort egyszerre állít.
- A bal oldali **Kedvezmény** minden tételsorra érvényes; soronként is megadható.
- A lapfül alján a **Σ** sor összegzi a mennyiséget, a nettó és a bruttó értéket.
- A már számlázott tétel sorszáma mellett pipa (✓) áll.
- Sor törlése: a sor végi **×**.

## Több eszköz egy munkalapon

Ha az ügyfél egyszerre több készüléket ad le, nem kell külön munkalap. A fő eszköz mellé akárhány további csatolható, és mindegyik önálló ügy a lapon belül.

### Lépésről lépésre: második eszköz felvétele

1. A munkalapon, az **Eszközök a lapon** panel jobb felső sarkában kattints az **Eszköz csatolása** gombra.
2. Válassz az ügyfél készülékei közül. Ha a készülék még nincs felvéve: **Új eszköz felvitele**.
3. Az eszköz új kártyaként megjelenik a panelen, a fő eszköz mellett.

### Az Eszközök a lapon panel

A munkalap jobb oldalán, az Ügyfél és az Eszköz mező alatt áll. Minden eszköz egy kártya, sorszámmal (1, 2, 3…); az első mindig a **fő eszköz**. Egy kártyán minden ott van, ami ahhoz az eszközhöz tartozik:

- **Kép** – a készülék termékképe (mockup) a kategóriája szerint; lásd [Mockupok](sugo:eszkozok).
- **Név és alcím** – gyártó, típus; alatta a kategória, az IMEI (vagy a sorozatszám) és a szín.
- **Állapot** – az eszköz saját állapotválasztója; a váltás azonnal mentődik. Új, még nem mentett eszköznél: *állapot: mentés után*.
- **díj** – a csatolt eszköz saját bevizsgálási díja (a mellette lévő gombbal a díjlistából is választható). A fő eszköz díja az előlegből adódik: *díj … Ft (az előlegből)*.
- **végösszeg · befizetve · fizetendő** – az eszköz saját összegei. Ha az eszköz ki van fizetve: *✓ Fizetve*.
- **Ceruza** és **×** – szerkesztés, illetve levétel a munkalapról (az eszköz az ügyfélnél megmarad).

A panel fejléce kiírja, melyik eszköz kész, például: *1 / 2 kész: 2. …*

### Mi külön eszközönként?

- **Hibák** – minden hibasorban megadod, melyik eszközé.
- **Tételek** – minden szolgáltatás- és terméksorban megadod, melyik eszközé. Az új sor az előző sor eszközét örökli.
- **Állapot** – minden eszköz kártyáján saját állapotválasztó van; a váltás azonnal mentődik.
- **Fizetve** – ha a Fizetés ablakban csak az egyik eszközt fizetik ki, az az eszköz *Fizetve* lesz, a lap nyitva marad.
- **Bevizsgálási díj** – eszközönként külön díj, a saját tételeiből levonva.
- **Összeg** – az eszköz kártyáján látod a végösszeget, a befizetett részt és a fizetendőt.
- **Címke** – eszközönként egy készül (1/2, 2/2).

### A közös állapot

Több eszköznél a lap állapota a **Közös állapot**, amely az eszközök állapotából adódik:

- Ha minden eszköz ugyanabban az állapotban van, az a közös állapot.
- Ha az egyik már kész (elkészült vagy lezárt), a másik még nem: **Részben elkészült**. A lap kiírja, melyik kész, például: *1 / 2 kész: 2. …*
- A lap csak akkor zárul le, ha minden eszköz lezárt.
- Az érvénytelen eszköz nem számít bele, amíg van másik.
- Ha a **Közös állapotot** írod át, **minden eszköz** azt az állapotot kapja.
- A *Részben elkészült* kézzel nem választható.

Ha egy eszközt leveszel a lapról, a hibái és a tételei nem vesznek el: a fő eszközhöz kerülnek.

## Bevizsgálási díj

A bevizsgálási díj az átvételkor fizetett összeg, amely a munkalapon és a számlán külön tételként jelenik meg.

### Lépésről lépésre: bevizsgálási díj rögzítése

1. A munkalap bal oldalán, a **Fizetés** részben kattints a **Díjlista** gombra, és válaszd ki az eszköz díját. A rendszer beírja az összeget a **Fizetett (előleg)** mezőbe, és bepipálja a **Bevizsgálási díj** jelölőt.
2. Díjlista nélkül: pipáld be a **Bevizsgálási díj** jelölőt, és írd az összeget a **Fizetett (előleg)** mezőbe.
3. Ments.

Mentéskor a díj külön tételsorként megjelenik a Szolgáltatások között.

**A díj beszámít a javításba.** Ha az eszközre javítási tétel kerül, a díj mínusz tételként levonódik a végösszegből – legfeljebb a többi tétel összegéig. Amíg nincs javítási tétel, a díj munkadíjként áll a lapon. A jelölő alatt a rendszer mindig kiírja, éppen mi történik a díjjal.

A bepipált jelölő alatt két gomb jelenik meg: az alap a **Javítás: levonódik**. A **Munkadíj marad** akkor kell, ha a díj kivételesen nem számít be a javításba.

- A díj- és a levonássort a rendszer tartja karban, a levonás sora nem szerkeszthető.
- Több eszköznél minden eszköznek saját díja van (a címkéjén), és a saját tételeiből vonódik le. Amelyik eszközt nem javítjátok, annak a díja munkadíjként megmarad.
- A díjlista a [Szolgáltatások](modul:szolgaltatasok) között állítható össze: az a szolgáltatás kerül bele, amelynél be van jelölve a **Bevizsgálási díj**.

> **Fontos:** Bepipált **Bevizsgálási díj** mellett a **Fizetett (előleg)** mezőbe kézzel írt összeg díjnak számít. További előleget a **Fizetés** gombbal, **Előleg / részfizetés** módban rögzíts – így a díj nem változik.

## Előleg, Fizetett és Fizetve

A munkalap **Fizetés** részében három dolog számít:

- **Fizetett (előleg)** – a ténylegesen befolyt összeg: bevizsgálási díj, előleg, részfizetés, és a végén a lezáró fizetés is. Kifizetett lapon a teljes végösszeg áll itt.
- **Fizetendő** – a bruttó összeg mínusz a fizetett. Az előleg tehát mindig levonódik. Kifizetett lapon nulla; ha az előleg több, mint a tételek értéke, negatív.
- **Fizetve** – a lap ki van egyenlítve. A **Fizetés ideje** a fizetés napja.

### Lépésről lépésre: fizetés rögzítése

1. Nyisd meg a mentett munkalapot, és kattints alul a **Fizetés** gombra.
2. Válaszd ki: **Teljes fizetés – lezárom** vagy **Előleg / részfizetés**.
3. Add meg a fizetési módot és az összeget. Több eszköznél pipáld ki, melyik eszközt fizetik.
4. Mentsd. A lap **Fizetett** összege nő; teljes fizetésnél a lap **Fizetve** lesz.

A Fizetés ablak a házipénztárba is beírhatja a tételt – erről a [Házipénztár](modul:penztar) szól.

### A „Fizetve” pipa levétele

Ha egy már kifizetett lapon kiveszed a **Fizetve** pipát, a rendszer előbb elmondja, mi fog történni, és három gombot ad:

- **Mégsem – maradjon fizetve** – semmi nem változik.
- **Fizetés visszavonása** – a lapot lezáró fizetés lekerül a Fizetett összegből, a díjak és a korábbi előlegek megmaradnak, a fizetendő visszajön. Utána **Mentés**.
- **Csak a pipát veszem ki** – a lap „nincs kifizetve” lesz, de a befizetett összeg a Fizetett mezőben marad, ezért a fizetendő nem jön vissza.

> **Figyelem:** A házipénztárból a tétel egyik esetben sem törlődik magától. Ha a fizetés nem történt meg, a pénztártételt a Házipénztárban kézzel kell törölni.

Több eszköznél az eszköz **Fizetve** jelzője külön levehető (az eszköz sora melletti **×**), amíg a lap egésze nincs kifizetve. A **Bevizsgálási díj** pipa levétele előtt a rendszer ugyanígy figyelmeztet: mentéskor a díj és a levonás sorai törlődnek, a befizetett összeg sima előlegként megmarad.

## Megjegyzések és gyakori megjegyzések

A munkalap alján két megjegyzés van:

- **Megjegyzés** – az ügyfél felé is megjelenhet (például nyomtatványon, az ügyféloldalon).
- **Belső megjegyzés** – csak a rendszerben látszik. Az ügyfél nem látja; az ügyfélnek szóló nyomtatványokra nem kerül ki, csak a **Belső munkalapra**.

A **Gyakori megjegyzések** gomb előre megírt szövegeket kínál. Kattints egyre – a **Megjegyzés** új soraként a lapra kerül. Többet is választhatsz; újra kattintva lekerül. A kézzel írt sorokhoz nem nyúl. Bármelyik állapotú lapon használható.

Új szöveget a **Más megjegyzés – írd be…** mezővel és a **Felvétel** gombbal adsz hozzá; az **A gyakori megjegyzések közé is** pipával a listára is felkerül. Végül **Kész**.

> **Figyelem:** A gyakori megjegyzések a *Megjegyzésbe* kerülnek, amelyet az ügyfél is láthat. Belső információt a **Belső megjegyzés** fülre írj.

## Címke és QR-kód

A munkalap **RMA** lapfülén áll a címke: QR-kód, vonalkód, munkalapszám, ügyfél, eszköz. A QR-kódot az ügyfél a telefonjával beolvassa, és a telefonszámával, valamint a munkalap sorszámával belépve látja a javítás állapotát.

- **Címke nyomtatása** – új lapon megnyílik a nyomtatható címke. Az eszköz sora nyomtatás előtt átírható.
- **Ügyféloldal** – megnézheted, mit lát az ügyfél.
- **Link másolása** – az ügyféloldal címe a vágólapra kerül.

Több eszköznél eszközönként egy címke készül; amelyik nem kell, annál vedd ki a pipát.

A címke csak akkor készül el, amikor a lap **munkalapszámot kapott**. Az RMA lapfül új lapnál a mentés után érhető el. Ugyanitt írhatsz üzenetet az ügyfélnek, és látod az **Állapottörténetet**.

## Lezárt lap zárolása

A lezárt állapotú lap (**Lezárt**, **Érvénytelen** – minden, ami a **Beállítások › Állapotok** listájában `zart` jelzős) **csak olvasható**:

- a lap tetején zöld sáv jelzi: **Lezárt lap – csak olvasható.**, mellette a lezárás napja,
- a mezők és a szerkesztő gombok le vannak tiltva, a **Mentés** gomb nem látszik,
- a szerver is elutasít minden módosítást: mentést, az eszköz állapotát és „fizetve” jelzőjét, fizetést (kivéve a már kifizetett lap utólagos pénztárba írását), a rendelés útját és az asszisztens javaslatát (megjegyzés, határidő),
- ami nem a lap tartalma, az így is megy: **Nyomtatás**, **Matrica**, számla, ügyfélüzenet az RMA lapfülön, az ügyfél és az eszköz adatlapja, **Napló**.

### Lépésről lépésre: lezárt lap újranyitása

1. Nyisd meg a lezárt lapot.
2. A lap tetején álló sávban válaszd ki, milyen állapotba kerüljön (alapból **Nyitott**).
3. Írd be, **miért** nyitod újra (ha a Beállításokban kötelező, enélkül nem megy).
4. Kattints az **Újranyitás** gombra, majd még egyszer (**Biztosan? Kattints újra**).
5. Az ablak újratölt, a lap szerkeszthető. Az újranyitás, az új állapot és az ok a lap **Naplójába** kerül.

A listában (Áttekintés) az állapot átírásával is újranyitható a lap – kivéve, ha az újranyitás oka kötelező: akkor csak a lapon, az okkal.

## Lezárt lapok (Beállítások)

A **Beállítások › Lezárt lapok** lapfülön (a **Munkalap** sorban):

- **A lezárt lap csak újranyitás után szerkeszthető** – alapból be van kapcsolva. Kikapcsolva a lezárt lap is szerkeszthető (minden módosítás akkor is a Naplóba kerül).
- **Az újranyitás oka kötelező** – bekapcsolva a lezárt lap csak a lap **Újranyitás** gombjával, kitöltött okkal nyitható újra.

## Nyomtatás, PDF és e-mail a munkalapról

A munkalap ablakának alján a **Nyomtatás** gomb (nyomtató ikon) megnyitja a lap nyomtatványait: **Átvételi elismervény**, **Munkalap**, **Belső munkalap**, **Árajánlat**, **Szállítólevél**. Mindegyik megnézhető előnézetben, kinyomtatható a kért példányszámban, elmenthető PDF-be és elküldhető e-mailben az ügyfélnek. Az Áttekintésen a kijelölt lap részletpaneljén is ott a gomb.

A rendszer magától is rákérdez:

- **új munkalap mentése után** az **átvételi elismervény** nyomtatására,
- **a lap lezárásakor** a **munkalap** nyomtatására.

A gomb csak mentett lapnál jelenik meg, és a nyomtatvány a lap **elmentett** adataiból készül. A részleteket – a példányszámot, a több eszközös lapot, az e-mailt és a nyomtatványok szerkesztését – a [Nyomtatás, PDF és e-mail](sugo:nyomtatas) fejezet írja le.

## Számlagombok a munkalapon

A munkalap ablakának alján két számlagomb van. Csak mentett, munkalapszámmal rendelkező lapnál jelennek meg.

- **Számlázz.hu számla** – a munkalap elmentődik, majd megnyílik a számla ablaka. Számla csak a kiállítás gombjára készül.
- **Számla-előkészítő (PDF)** – letölthető, nyomtatható PDF a tételekből. Helyben készül; **nem számla**. A gomb felirata a Számlázás beállításában választott nyomtatványnév szerint más is lehet.

A gombok mellett a lap már kiállított számláinak sorszáma áll; kattintásra megnyílik a PDF.

A számla ablakát, a beállításokat és az eszközönkénti számlázást a [Számlázás](sugo:szamlazas) fejezet írja le.

## A Munkalapok lista

A [Munkalapok](modul:munkalapok) menüpont a javítási lapok listája. Árukiadás és rendelés nincs benne.

- **Kereső:** munkalapszám, név, ügyfél, telefon, IMEI, készülék. A csatolt eszközök IMEI-jére, sorozatszámára és típusára is talál.
- **Állapotszűrő:** alapból **Nyitott munkalapok**. A **Mind** minden lapot mutat; alatta egyenként az állapotok (itt találod az árajánlatokat és a sablonokat is). A választás azonnal érvényesül.
- **Szűrő törlése:** visszaáll az alapnézet.
- **Oszlopok:** **Szám, Állapot, Ügyfél, Eszköz, Név, Felelős, Határidő, Hibák**. Az ügyfél alatt a telefonszám és a cím, az eszköz alatt az IMEI áll. A Hibák oszlop azt mutatja, hány hiba kész (például *1 / 2 kész*).
- **Megnyitás:** a számra vagy a **Megnyit** gombra kattintva.
- **Állapot:** a sorban, a színes állapotra kattintva is átállítható.
- A legnagyobb számú lap áll elöl. Egy oldalon 25 lap fér el, alul lapozó.
- A lejárt határidő kiemelve látszik; a lezárt lapok sora halvány színt kap.

Ha többféle szűrés, rendezés vagy összesítés kell, használd az Áttekintés rácsát.

## Állapotok

**Beállítások › Állapotok** (a *Munkalap* csoportban). Itt szerkeszted a munkalap állapotlistáját. Csak adminisztrátor éri el.

Soronként egy állapot, ebben az alakban: `kulcs | Név | szín | jelzők`

- **kulcs** – a rendszer ezt tárolja. Ha már használatban van, ne írd át; a **Nevet** szabadon átírhatod.
- **szín** – `sarga`, `zold`, `szurke`, `kek`, `narancs`, `olajzold`, `lila`, `piros`.
- **jelzők** – vesszővel elválasztva:

| Jelző | Jelentése |
|---|---|
| `szamozott` | Ebben az állapotban a lap munkalapszámot kap. |
| `zart` | A lap lezárt, nem számít nyitottnak. |
| `alap` | Az új munkalap ezzel indul (több esetén az első). |
| `kesz` | Az eszköz elkészült, átvehető. |
| `reszben` | A több eszközös lap közös állapota; magától áll be. |
| `ervenytelen` | Az ilyen eszköz nem számít bele a közös állapotba. |

A sorok sorrendje a lista sorrendje – így rendez az Áttekintés Állapot oszlopa is. Üresen hagyva és mentve az alaplista jön vissza.

> **Példa:** `alkatreszre_var | Alkatrészre vár | kek | szamozott` – új, számozott, nyitottnak számító állapot.

## Hibasor-állapotok

**Beállítások › Hibasor-állapotok**. A munkalap hibasorainak saját állapotlistája, ugyanabban az alakban: `kulcs | Név | szín | jelzők`.

Az alaplista: **Új**, **Folyamatban**, **Kész**, **Nem javítható**. A `zart` jelzős állapot számít késznek a *1 / 2 kész* számlálóban. A `szamozott` jelzőnek itt nincs jelentése.

## Gyakori hibák

**Beállítások › Gyakori hibák**. A munkalap Hibák lapfülén, a **Gyakori hibák** gombbal felvehető hibák listája.

- Soronként egy hiba; a sorrend a lista sorrendje.
- Legfeljebb 60 hiba fér bele. Az ismétlődő és az üres sorok mentéskor kimaradnak.
- A kollégák a munkalapról is bővíthetik: az új hiba a lista végére kerül.
- Üresen hagyva és mentve a húsz leggyakoribb készülékhiba jön vissza.

## Gyakori megjegyzések

**Beállítások › Gyakori megjegyzések**. A munkalap **Gyakori megjegyzések** gombjával választható, előre megírt szövegek.

- Soronként egy megjegyzés, legfeljebb 60.
- A munkalap **Megjegyzés** mezőjébe kerülnek, amelyet az ügyfél is láthat – ide ne írj belső szöveget.
- A kollégák a munkalapról is bővíthetik.
- Üresen hagyva és mentve az alaplista jön vissza (például: *Az ügyfél telefonos értesítést kér*, *Sürgős javítás*).

## Számozás

**Beállítások › Számozás**. A munkalapszám kezdőértéke és megjelenése. A lapfül tetején látod, mi lesz a következő kiadott szám.

| Mező | Mire való |
|---|---|
| **Következő szám (legalább)** | A számozás kezdőértéke. Csak *előre* ugrathatja a számozást: ha már van ennél nagyobb kiadott szám, az számít. |
| **Előtag** | A szám elé kerülő szöveg, legfeljebb 10 karakter, például `M-`. Üresen csak a szám látszik. |
| **Kitöltés (számjegyek)** | Ennyi számjegyre egészíti ki nullákkal. `0` = nincs kitöltés; `6`-nál a 34983 így jelenik meg: `034983`. |

Az előtag és a kitöltés csak a megjelenítést változtatja, a régi lapok száma is az új alakban látszik.

## Szabályok, amiket a rendszer betart

- **Munkalapot nem lehet törölni.** A tévedésből felvett lapot tedd **Érvénytelen** állapotba: lezártnak számít, a száma megmarad, és visszakereshető.
- Számozott állapothoz ügyfél és eszköz kell. A kiválasztott eszköznek az ügyfél készülékének kell lennie.
- A munkalapszámot a rendszer osztja ki, kézzel nem írható át, és két lap nem kaphatja ugyanazt.
- Az előleg mindig levonódik a végösszegből.
- A **Fizetett** a ténylegesen befolyt összeg.
- A tételek ára bruttó; a mérvadó összeget mentéskor a rendszer számolja újra.
- A számlagombok és a címke csak munkalapszámmal rendelkező lapnál érhetők el.
- **A lezárt lap csak újranyitás után szerkeszthető**, és az újranyitás a Naplóba kerül (lásd: Lezárt lap zárolása).
- A lap minden változása – mezők, tételek, hibák, eszközök, fizetés, üzenetek – a lap **Naplójába** kerül, és onnan nem törölhető.
- Ha egy fizetési mód vagy egy felelős már nincs a listán, a régi lapon akkor is látszik.

## Ha valami nem megy

- **„Válassz ügyfelet a listából – gépelés önmagában nem elég.”** – A nevet begépelted, de nem választottad ki a listából.
- **„Válassz ügyfelet a listából – ügyfél nélkül nem adható munkalapszám.”** – Számozott állapothoz ügyfél kell. Válassz ügyfelet, vagy tedd a lapot Árajánlat állapotba.
- **„Válassz eszközt – a munkalap mindig egy eszközre vonatkozik.”** – Számozott állapothoz eszköz is kell.
- **„A kiválasztott eszköz nem ennek az ügyfélnek a készüléke.”** – Ügyfelet váltottál. Válaszd ki újra az eszközt.
- **„A Mentéssel együtt mentődik.”** az állapot alatt – az állapot még nem menthető azonnal (például az ügyfél még nincs elmentve a lapon). Kattints a **Mentés** gombra.
- **„A számozás épp foglalt – próbáld újra egy pillanat múlva.”** – Egy kolléga ugyanakkor ment. Kattints újra a mentésre.
- **Nincs számlagomb.** – A lapnak még nincs munkalapszáma, vagy még nincs elmentve.
- **„Ez a lap lezárt – szerkeszteni csak újranyitás után lehet”** – a lap lezárt állapotban van. Nyisd újra a lap tetején az **Újranyitás** gombbal.
- **„A lezárt lap újranyitásához ok kell”** – a Beállításokban kötelező az újranyitás oka: nyisd meg a lapot, és a tetején írd be az okot.
- **„Előbb válassz ügyfelet – az eszköz mindig egy ügyfélhez tartozik.”** – Eszközt csak kiválasztott ügyfél mellé lehet csatolni.

## GYIK

### Miért nem tudom szerkeszteni a lezárt lapot?

Mert a lezárt lap csak olvasható – így nem változhat meg utólag észrevétlenül. A lap tetején az **Újranyitás** gombbal nyisd újra; az újranyitás és az oka a Naplóba kerül.

### Lezárt lapról is nyomtathatok és számlázhatok?

Igen. A nyomtatás, a matrica, a számla és az ügyfélüzenet nem módosítja a lapot, ezért zárolva is megy.

### Hol látom, ki módosított egy munkalapot?

A lap ablakának alján a **Napló** gombbal: időrendben minden változás, a régi és az új értékkel, a kolléga nevével.

### Miért nem kap munkalapszámot a lap?

Mert még nem volt számozott állapotban. Az Árajánlat és a Sablon nem ad számot. Tedd a lapot például Nyitott állapotba – ehhez ügyfél és eszköz kell.

### Mikor kap számot az árajánlat?

Amikor először számozott állapotba teszed (például Nyitott). Akkor megkapja a következő szabad számot.

### Megváltozhat egy munkalap száma?

Nem. A kiosztott szám végleges, akkor is, ha a lapot később más állapotba teszed.

### Hogyan törlök egy tévedésből felvett munkalapot?

Sehogy: munkalap nem törölhető. Tedd **Érvénytelen** állapotba. Így nem számít nyitottnak, de a száma és a története megmarad.

### Felvehetek munkalapot ügyfél nélkül?

Csak nem számozott állapotban (Árajánlat, Sablon). Számozott laphoz ügyfél és eszköz kell.

### Az ügyfél még nincs a rendszerben. Ki kell lépnem a munkalapból?

Nem. Az Ügyfél mező melletti **+** gombbal a lap fölött veheted fel; mentés után már ki is van választva.

### Hogyan javítom ki az ügyfél telefonszámát a munkalapról?

Az Ügyfél mező melletti **ceruzával**, vagy az ügyfél kártyáján a **Szerkesztés** gombbal. Mentés után a lap megmarad, az adat frissül.

### Miért üres az eszközlista?

Mert még nincs ügyfél kiválasztva, vagy az ügyfélnek nincs felvett készüléke. Az Eszköz mező melletti **+** gombbal vegyél fel egyet.

### Hogyan veszek fel második eszközt?

A **További eszközök** sorban az **Eszköz csatolása** gombbal. Az ügyfél készülékei közül választhatsz, vagy újat vehetsz fel.

### Mi történik, ha leveszek egy eszközt a munkalapról?

Az eszköz az ügyfélnél megmarad. A hibái és a tételei a fő eszközhöz kerülnek, a bevizsgálási díja kikerül az előlegből.

### Mit jelent a „Részben elkészült”?

Több eszközös lapon az egyik eszköz már kész, a másik még nem. A rendszer magától állítja be, és kiírja, melyik eszköz kész.

### Miért nem tudom kiválasztani a „Részben elkészült” állapotot?

Mert ez a közös állapot magától áll be. Az eszközök állapotát állítsd külön, a bal oldali **Eszközönként** részben.

### Az egyik készüléket elviszik, a másik marad. Mit csináljak?

Az elvitt eszköz állapotát állítsd Lezártra, a fizetését a **Fizetés** ablakban csak arra az eszközre rögzítsd. A lap nyitva marad, amíg a másik eszköz nincs kész.

### Kell Mentést nyomnom állapotváltás után?

Mentett lapon nem: az állapot azonnal mentődik. Új lapon igen, ott a létrehozással együtt mentődik.

### Hogyan veszek fel olyan hibát, ami nincs a gyakori hibák között?

Írd be közvetlenül a hibasorba, vagy a Gyakori hibák ablak alján a **Más hiba – írd be…** mezőbe.

### Miért tűnt el az üres hibasor mentés után?

Az üresen hagyott hibasort a rendszer mentéskor eldobja. Ez szándékos.

### Nettó vagy bruttó árat írjak a tételhez?

Bruttót. A **Bruttó ár** mezőbe az áfás egységár kerül, a nettót a rendszer számolja.

### Hogyan adok kedvezményt?

Egy tételre a sor **Kedv. %** mezőjében. Az egész lapra a bal oldali **Kedvezmény** mezőben – ez minden tételsorra érvényes.

### Olyan szolgáltatás kell, ami nincs a listán. Mit tegyek?

A választó ablakban a **+ Új szolgáltatás** gombbal felveheted a listára, vagy a **Kézzel írom be** gombbal csak erre a lapra írod be.

### Mi a különbség az előleg és a bevizsgálási díj között?

Mindkettő a **Fizetett (előleg)** mezőben áll, és levonódik a végösszegből. A bevizsgálási díj ezen felül külön tételként megjelenik a lapon és a számlán.

### Mi történik a bevizsgálási díjjal, ha az ügyfél kéri a javítást?

Beszámít: mínusz tételként levonódik a végösszegből, legfeljebb a többi tétel összegéig.

### És ha az ügyfél nem kéri a javítást?

Akkor nincs miből levonni: a díj munkadíjként a lapon marad.

### Mi történik az előleggel, ha a javítás olcsóbb lett, mint az előleg?

A **Fizetendő** negatív lesz – ennyi jár vissza az ügyfélnek.

### Hogyan rögzítek újabb előleget egy lapra, amelyen már van bevizsgálási díj?

A **Fizetés** gombbal, **Előleg / részfizetés** módban. Ne a Fizetett mezőt írd át kézzel, mert az díjnak számítana.

### Miért nagyobb a „Fizetett”, mint az előleg, amit rögzítettem?

Mert a Fizetett minden befolyt összeget tartalmaz: a díjat, az előlegeket és a lezáró fizetést is.

### Tévedésből fizetettre állítottam a lapot. Hogyan vonom vissza?

Vedd ki a **Fizetve** pipát, és válaszd a **Fizetés visszavonása** gombot, majd ments. Ha pénztártétel is készült, azt a Házipénztárban kézzel töröld.

### Mi a különbség a „Fizetés visszavonása” és a „Csak a pipát veszem ki” között?

A visszavonás a lezáró fizetést is leveszi, így a fizetendő visszajön. A másik csak a jelzőt veszi le, az összeg a Fizetett mezőben marad.

### Hol nyomtatom ki az átvételi elismervényt és a munkalapot?

A munkalap ablakának alján, a **Nyomtatás** gombbal. Új lap mentése után és a lap lezárásakor a rendszer magától is felajánlja. Lásd: [Nyomtatás, PDF és e-mail](sugo:nyomtatas).

### El tudom küldeni az ügyfélnek a munkalapot vagy az árajánlatot e-mailben?

Igen. A lap **Nyomtatás** ablakában válaszd ki a nyomtatványt, majd a **Küldés e-mailben** gombot. A nyomtatvány PDF csatolmányként megy az ügyfél címére.

### Hol nyomtatok címkét?

A munkalap **RMA** lapfülén, a **Címke nyomtatása** gombbal.

### Miért nincs QR-kód a munkalapon?

Mert a lapnak még nincs munkalapszáma. A QR-kód és a vonalkód akkor készül el, amikor a lap számozott állapotba kerül.

### Látja az ügyfél a megjegyzést?

A **Megjegyzést** láthatja. A **Belső megjegyzést** nem.

### Hogyan veszek le egy gyakori megjegyzést a lapról?

Nyisd meg a **Gyakori megjegyzések** ablakot, és kattints újra a kiemelt szövegre. Vagy töröld ki a sort a Megjegyzés mezőből.

### Miért nem látom a számlagombokat?

Csak mentett, munkalapszámmal rendelkező lapnál jelennek meg. Árajánlatnál és új, még nem mentett lapnál nincsenek.

### Számla a „Számla-előkészítő (PDF)”?

Nem. Nyomtatható összesítő a tételekből. Számlát a **Számlázz.hu számla** gombbal állítasz ki – lásd: [Számlázás](sugo:szamlazas).

### Miért nem látom a lezárt munkalapot a Munkalapok listában?

Mert a lista alapból csak a nyitott lapokat mutatja. Az állapotszűrőben válaszd a **Mind** vagy a **Lezárt** értéket.

### Hol találom az árajánlatokat?

A Munkalapok lista állapotszűrőjében az **Árajánlat** értéknél, vagy az Áttekintés **Árajánlatok és sablonok** nézetében.

### Hogyan állítom be, honnan induljon a számozás?

**Beállítások › Számozás**, **Következő szám (legalább)** mező. Visszafelé nem állítható: a már kiadott legnagyobb számnál kisebbet a rendszer nem vesz figyelembe.

### Hogyan veszek fel új állapotot?

**Beállítások › Állapotok**: írj új sort `kulcs | Név | szín | jelzők` alakban, és ments.

### Van segítség, ha először látom a Munkalapok oldalt?

Igen. Kérd meg a jobb alsó sarokban lévő asszisztenst: „Vezess végig ezen az oldalon”.

## Túra: munkalapok

- `.sdh-fejlec__gombok` | + Új munkalap | Ezzel a gombbal veszel fel új javítási ügyet. A munkalap popupban nyílik, a listát nem kell elhagynod.
- `.sdh-kereso input[type="search"]` | Kereső | Munkalapszámra, ügyfélnévre, telefonszámra, IMEI-re és készülékre is kereshetsz.
- `.sdh-kereso select[data-sdh-szuro]` | Állapotszűrő | Alapból a nyitott munkalapokat látod. Itt váltasz az összesre vagy egy-egy állapotra.
- `.sdh-kereso__talalat` | Találatok | Ennyi munkalap felel meg a keresésnek és a szűrőnek.
- `.sdh-tabla thead` | Oszlopok | Szám, állapot, ügyfél, eszköz, név, felelős, határidő és a hibák állása.
- `.sdh-tabla [data-sdh-munkalap-szam]` | Munkalapszám | A számra kattintva megnyílik a munkalap. Gondolatjel áll itt, amíg a lap nem kapott számot.
- `.sdh-tabla [data-sdh-allapot-hely]` | Állapot a sorban | Kattints rá, és válassz új állapotot – azonnal mentődik, a lapot meg sem kell nyitnod.
- `.sdh-tabla td:last-child .sdh-gomb` | Megnyit | A munkalap szerkesztése: hibák, tételek, fizetés, címke, számla.
- `.sdh-lapozo` | Lapozó | Egy oldalon 25 munkalap fér el. Itt lépsz a többire.
