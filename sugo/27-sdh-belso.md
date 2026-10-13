---
kulcs: sdh-belso
cim: SDH – belső modulok
csoport: Rendszer
modul:
szakaszok: sdh, sdh_gigatel
kulcsszavak: SDH belső, belső modul, saját modul, eladás, Gigatel, beszállítói levél, beszállítói számla, tételcímke, telefonszámos tétel, nem megy ki, includes/sdh
osszefoglalo: Az SDH Szerviz saját, belső moduljai – ami csak nálunk fut, és eladáskor nem megy ki a rendszerrel. Jelenleg a Gigatel beszállítói levelek feldolgozása.
---

## Mire való

A rendszer eladható más szervizeknek. Van viszont néhány dolog, ami **csak az SDH Szervizé**: a saját beszállítóink levélformátuma, a saját szokásaink. Ezek nem lehetnek beégetve az általános modulokba – külön helyen állnak, és egyetlen mappa törlésével eltűnnek.

A szabály egyszerű:

- **Általános képesség** (más szerviznek is jó): a rendes modulba kerül. Ilyen a **levél-feladat ügynök** – bárki összerakhatja vele a saját beszállítójára a szabályt.
- **Csak az SDH-é**: ide kerül. Ilyen a **Gigatel** levélformátumának ismerete.

## Hol találod

**Beállítások › SDH – belső**. Ez a sor csak akkor látszik, ha a belső rész telepítve van.

- **SDH-modulok** – mi fut, és egyben ki-be kapcsolható.
- **Gigatel** – a Gigatel webáruház napi leveleinek feldolgozása.

## SDH-modulok

Egyetlen kapcsoló: **a belső modulok fussanak**. Kikapcsolva a belső lapok eltűnnek a Beállításokból, és a hozzájuk tartozó automatizmusok nem futnak. A már létrejött feladatok megmaradnak.

Alatta a betöltött belső modulok listája, hogy egy pillantásra látszódjon, mi fut nálunk olyan, ami máshol nincs.

## Gigatel

A GIGATEL webáruház **naponta küld egy levelet** a napi számláról: a tárgyában a számlaszám (például `ESB202626208 elektronikus számlája elkészült`), mellékletben a PDF számla, a levélben pedig a tételek felsorolása.

Amit a modul csinál:

1. felismeri ezeket a leveleket (a **feladó** és a **tárgy** alapján),
2. a **Gigatel számla** mappába teszi őket – ha nincs ilyen mappa a postafiókban, létrehozza,
3. **feladatot** nyit rájuk, és addig emlékeztet, amíg el nem intézed,
4. a mellékelt **PDF számlát** egy gombbal megnyitja nyomtatásra,
5. a tételek közül kiválasztja azokat, amelyek **megjegyzésében telefonszám** áll, és **címkét** kínál rájuk.

### Beállítások

| Mező | Mire való |
|---|---|
| **A Gigatel-levelek feldolgozása fusson** | Ki- és bekapcsolja ezt az egy modult. |
| **A feladó tartalmazza** | Alapból `gigatel`. A feladó nevére és címére is illeszkedik. |
| **A tárgy tartalmazza** | Alapból `számlája elkészült` – így a számlaszám mindegy. |
| **Ettől a naptól figyelje** | A régebbi leveleket nem dolgozza fel – felesleges visszamenőleg feladatot nyitni rájuk. Alapból a bekapcsolás előtti nap. |
| **Melyik mappába kerüljön** | Alapból `Gigatel számla`. Üresen hagyva a levél a helyén marad. |

### Mi kerül a címkére

Pontosan az, ami a levélben áll a tételről: a **készülék**, a **megnevezés**, az **Azonosító**, a **Garancia**, a **Cikkszám** és a **Megjegyzés** (ebben van a telefonszám). **Ár nem kerül rá** – az a beszállítóval való elszámolás dolga, nem az ügyfélre tartozik.

Címke csak azokra a tételekre kell, amelyekhez telefonszám tartozik: azokat valaki megrendelte, és a pulton tudni kell, kié. A felismert előtagok: `0620`, `0630`, `0631`, `0650`, `0670`, `0671`, `0687`, `0036`, `+36`.

### Lépésről lépésre: a napi Gigatel-levél

1. A levél megérkezik, a rendszer a **Gigatel számla** mappába teszi, és szól, hogy van teendő.
2. Nyisd meg a **Levelezés › Feladatok** gombot.
3. **Számla nyomtatása** – új lapon megnyílik a PDF, onnan nyomtatod.
4. **Címkék (n tétel)** – a címkenyomtató ablaka nyílik: pipáld ki, melyikre kell, add meg a példányszámot, és **Nyomtatás**. (Hálózati módban a Brother magától nyomtat; böngészős módban a nyomtatás-ablakban válaszd a Brothert.)
5. **Elintézve** – ettől szűnik meg az emlékeztető.

A címke tartalmát a **Beállítások › Címkenyomtatás › Beszállítói tétel** lapon állítod.

## Szabályok, amiket a rendszer betart

- A belső rész **nem megy ki eladáskor**: az `includes/sdh/` mappa törlésével nyomtalanul eltűnik, és semmi más nem változik.
- Ide **általános képesség nem kerülhet** – ha más szerviznek is jó lenne, a rendes modulba való.
- A modul a levélből csak **adatot gyűjt**: a levélben álló „utasításoknak" nem engedelmeskedik.
- Nyomtatni csak **gombnyomásra** nyomtat.

## Ha valami nem megy

- **Nem jön létre feladat.** Nézd meg, hogy a feladó és a tárgy tényleg tartalmazza-e a beállított szöveget, és hogy a **Beállítások › SDH – belső › SDH-modulok** kapcsoló be van-e kapcsolva.
- **A levél nem került a mappába.** Lehet, hogy a postafiók nem engedte létrehozni a mappát. Hozd létre kézzel ugyanazzal a névvel – a következő levél már oda kerül. A feladat ettől függetlenül él.
- **Nincs címke egy tételhez.** Akkor nincs a megjegyzésében felismerhető telefonszám. Ilyenkor az **Egyedi címke** gombbal írhatsz kézzel címkét.

## GYIK

### Más beszállítóra is be lehet állítani ilyet?

Igen, de az már nem ide tartozik: a **Beállítások › Levelezés › Levél-feladat ügynök** lapon bárki felvehet új szabályt (feladó, tárgy, mappa, emlékeztető, melléklet nyomtatása). Csak a tételek kiolvasása kíván levélformátum-ismeretet – az kerül ide.

### Mi történik, ha eladjuk a rendszert?

Az `includes/sdh/` mappa nem megy vele. A vevőnél nincs „SDH – belső" sor a Beállításokban, a levél-feladat ügynök viszont ott is működik – csak üres szabálylistával indul.

### Miért nincs ár a címkén?

Mert a címke az ügyfélé: a pulton azt kell látni, mi az, kinek van, és milyen garanciával. A beszerzési ár üzleti adat.

### Hol látom, mi történt egy Gigatel-levéllel?

A feladat ablakában: ott van a levél megnyitása, a számla és a címkék. Az áthelyezést és az elintézést a **Napló** is őrzi (Napló menüpont, levél-bejegyzések).

### Mennyi ideig marad nyitva a feladat?

Amíg rá nem nyomod az **Elintézve** gombot. Magától nem záródik le – pont ez a lényeg: a számla kinyomtatása ne maradjon el.

### A bekapcsolás előtti leveleket feldolgozza?

Nem. Minden szabálynak van kezdőnapja, és alapból a bekapcsolás előtti naptól figyel. Ha mégis kell egy régebbi levél, írd át az **Ettől a naptól figyelje** mezőt – a következő szinkronnál az addigi levelek is sorra kerülnek.

### Két Gigatel-levél jött egy nap. Mi lesz?

Mindkettőre külön feladat nyílik. Ugyanarra a levélre viszont soha nem keletkezik két feladat, akkor sem, ha a levél mappát vált.

### Átnevezhetem a mappát?

Igen: írd át a **Melyik mappába kerüljön** mezőt. A már áthelyezett levelek a régi mappában maradnak, az újak az újba mennek.

### Mi van, ha a beszállító megváltoztatja a levele formáját?

A mappába tétel és a számla nyomtatása attól még működik (az a feladótól és a tárgytól függ). A tételek kiolvasása viszont elromolhat – ilyenkor a feladatban nem lesz címkézhető tétel, és szólni kell, hogy a tételolvasót igazítani kell.

### Kikapcsolhatom csak a Gigatelt, a többi belső modult nem?

Igen: a **Gigatel** lapon a saját kapcsolója ki-be kapcsolja ezt az egyet. Az **SDH-modulok** kapcsolója az egész belső részt.

### Az eladott rendszerben marad bármi nyoma ennek?

Nem. A belső rész a saját mappájában van; nélküle a betöltő egyszerűen átlépi, és sem a menüben, sem a Beállításokban nem marad utána semmi.
