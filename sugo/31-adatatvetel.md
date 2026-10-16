---
kulcs: adatatvetel
cim: Adatátvétel a régi programból
csoport: Rendszer
modul:
szakaszok: adatatvetel
kulcsszavak: adatátvétel, import, átvétel, régi program, MunkaLap 3, munkalapszoftver, CSV, XLSX, Excel, táblázat, ügyfelek átvétele, munkalapok átvétele, régi adatok, költözés, átállás, visszavonás, próba
osszefoglalo: A Beállítások › Adatátvétel lapon a régi program (például a MunkaLap 3) vagy bármilyen táblázat adatait veszed át – ügyfelek, eszközök, munkalapok, tételek, hibák –, előbb írás nélküli próbával, utána adagokban, visszavonhatóan.
---

## Mire való

Átálláskor a régi adatok – akár több tízezer munkalap – egyszerre jönnek át, kézi gépelés nélkül. A rendszer táblázatfájlokat olvas (CSV, TXT vagy XLSX), ezeket a régi program mentéséből kapod.

## Hol találod

**Beállítások › Rendszer › Adatátvétel**. Csak adminisztrátor látja.

## Mielőtt elkezded

1. Készíts **mentést**: Beállítások › Mentés › Mentés most.
2. A régi programban mentsd ki a táblázatokat modulonként: **Ügyfél**, **Tárgy** (eszköz), **Munkalap**, **Tétel**, **Hiba**. A MunkaLap 3 CSV, TXT és XLS formában tud menteni. A régi **.xls** fájlt nyisd meg egy táblázatkezelőben, és mentsd **.xlsx**-ként vagy CSV-ként.
3. Az átvétel sorrendje mindig: **1. Ügyfelek → 2. Eszközök → 3. Munkalapok → 4. Tételek → 5. Hibák.** Az eszköz az ügyfelére, a munkalap az ügyfelére és az eszközére, a tétel és a hiba a munkalapjára hivatkozik a régi azonosítóval.

## Lépésről lépésre: egy táblázat átvétele

1. **Mit tartalmaz a fájl?** – válaszd ki (például Ügyfelek).
2. Válaszd ki a fájlt, majd **Feltöltés és elemzés**. A rendszer maga felismeri a kódolást (az ékezeteket a régi Windows-os fájlban is) és az elválasztót (pontosvessző, vessző, tabulátor).
3. **Oszlopok párosítása:** minden mezőhöz a fájl egy oszlopát javasolja a fejléc alapján, mellette mintát mutat. Ellenőrizd; ahol rossz, válaszd ki a jót. A csillagos mező kötelező (például az ügyfél neve és régi azonosítója).
4. Munkalapoknál az **Állapotok párosítása** mezőben megadhatod, melyik régi állapot melyik új legyen (soronként: `Kiadva = lezart`). Üresen a rendszer maga dönt (a kiadott, átadott → lezárt; a kész → elkészült; az érvénytelen, sztornó → érvénytelen; a többi → az alapállapot).
5. **Próba (nem ír):** végigolvassa az egész fájlt, és megmutatja: hány sor jó, hány van már bent, hány hibás és miért (az első 25 sor számával), munkalapoknál és tételeknél a bruttó végösszeget, és hogy melyik állapotba hány lap kerül. Vesd össze a régi program összesítőjével.
6. Ha rendben, **Átvétel indítása** (kétszer kattints). 500 soronként halad, közben mutatja, hol tart. Nagy fájlnál ez percekig tarthat – hagyd nyitva az oldalt.
7. A végén összesítést látsz, és az átvétel bekerül az **Eddigi átvételek** táblázatba.

## Amit a rendszer magától intéz

- A **dátumokat** többféle alakban felismeri (`2019.03.05.`, `05.03.2019`, `2019-03-05`, a táblázatkezelő dátumszáma).
- A **pénzösszegekből** kiveszi a szóközt, a „Ft”-ot, és a tizedesvesszőt is érti.
- A **feloldó kódot és mintát** titkosítva tárolja.
- Az ügyfeleknek **ügyfélszámot** ad, ha a fájlban nincs, és feltölti a gyorskeresőt.
- Adószámmal rendelkező ügyfél **cég** lesz.
- A tétel típusa (termék vagy szolgáltatás) a fájl „Típus” oszlopából jön; ha nincs, szolgáltatás.
- A munkalap **munkalapszáma** megmarad; az új munkalapok a legnagyobb átvett szám után folytatják a számozást.
- Minden átvett sor megkapja a régi azonosítóját, és „munkalap3” forrásként jelölődik.

## Visszavonás

Az **Eddigi átvételek** táblázatban minden átvétel mellett ott a **Visszavonás** gomb (kétszeri kattintás). Az adott átvétel minden új sorát törli. Amit az átvétel kihagyott (mert már bent volt), ahhoz nem nyúl.

Ha több táblázatot is átvettél, fordított sorrendben vond vissza: előbb a hibákat és tételeket, utána a munkalapokat, az eszközöket, végül az ügyfeleket.

## Szabályok, amiket a rendszer betart

- A próba semmit nem ír az adatbázisba.
- Ugyanaz a sor kétszer nem kerül be: a régi azonosító alapján a már bent lévőt kihagyja (a hibáknál a munkalap + a hiba szövege alapján).
- A már foglalt munkalapszámú sort nem veszi át, hanem hibaként jelzi.
- Hivatkozás nélküli sort (például ismeretlen ügyfélre mutató eszközt) nem vesz át, hanem hibaként jelzi.
- A Naplóba adagonként egy összesítő bejegyzés kerül, nem soronként.
- A feltöltött fájlok védett mappába kerülnek, kívülről nem érhetők el.

## Tippek

- Előbb egy kis mintával (néhány száz sor) próbáld ki, nézd meg néhány átvett munkalapot, és csak utána a teljes fájlt.
- Ha a próba sok „nincs meg” hibát ad, valószínűleg rossz oszlopot párosítottál a hivatkozáshoz (például az ügyfél nevét az ügyfél azonosítója helyett).
- A teljes átvétel után futtasd a **Riportokat** egy régi időszakra, és vesd össze a régi program számaival.

## Ha valami nem megy

- **„A régi .xls formátumot nem tudom olvasni”** – nyisd meg táblázatkezelőben, és mentsd .xlsx-ként vagy CSV-ként.
- **Kérdőjelek vagy furcsa betűk az ékezetek helyén** – a fájlt más kódolással mentették; mentsd újra „CSV UTF-8” formában.
- **„az ügyfél (…) nincs meg – előbb az ügyfeleket vedd át”** – a sorrend: előbb az ügyfelek, csak utána az eszközök és a munkalapok.
- **„a … munkalapszám már foglalt”** – ilyen számú munkalap már van. Ha egy korábbi próbaátvételből maradt, vond vissza azt.
- **Megszakadt az átvétel** – az addig átvett sorok bent vannak; indítsd újra: a már bent lévőket kihagyja és folytatja.

## GYIK

### Hogyan hozom át a régi programból az adatokat?

Mentsd ki a régi program táblázatait (Ügyfél, Tárgy, Munkalap, Tétel, Hiba), és a **Beállítások › Adatátvétel** lapon ebben a sorrendben vedd át őket.

### Milyen fájlt tud olvasni?

CSV-t, TXT-t (tabulátorral vagy pontosvesszővel elválasztva) és XLSX-et. A régi .xls-t előbb mentsd .xlsx-ként.

### Mi történik, ha kétszer veszem át ugyanazt?

Semmi baj: a már bent lévő sorokat a régi azonosító alapján kihagyja, nem lesz kettő belőlük.

### Visszavonhatom az átvételt?

Igen, az Eddigi átvételek táblázatban a Visszavonás gombbal. Az átvétel minden új sora törlődik.

### Mire jó a próba?

Írás nélkül megmutatja, hány sor jó, hány hibás és miért, és mennyi a végösszeg – így az élesítés előtt látod, mi fog történni.

### Megmaradnak a régi munkalapszámok?

Igen. Az új munkalapok a legnagyobb átvett szám után folytatják a számozást.

### Mi lesz a régi állapotokkal?

Az Állapotok párosítása mezőben megadhatod; üresen a rendszer a hasonló nevű állapotra teszi (kiadva → lezárt, kész → elkészült).

### Átjönnek a feloldó kódok?

Igen, titkosítva, ahogy a rendszer minden feloldó kódot tárol. Csak az láthatja, akinek erre joga van.

### Mennyi ideig tart 40 000 munkalap átvétele?

Adagonként 500 sor; a teljes idő a géptől függ, több perc is lehet. Hagyd nyitva az oldalt; ha megszakad, újraindítva folytatja.

### Mi van, ha a fájl fejlécei másképp szólnak?

Az oszlopok párosítását kézzel is beállíthatod: minden mezőhöz kiválasztod a fájl megfelelő oszlopát.

### Kell előtte mentés?

Igen, erősen ajánlott: Beállítások › Mentés › Mentés most. A visszavonás mellett ez a biztos visszaút.
