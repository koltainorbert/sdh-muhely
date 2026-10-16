---
kulcs: sdh-belso
cim: SDH – belső modulok
csoport: Rendszer
modul:
szakaszok: sdh, sdh_gigatel, sdh_3d
kulcsszavak: SDH belső, belső modul, saját modul, eladás, Gigatel, beszállítói levél, beszállítói számla, tételcímke, telefonszámos tétel, nem megy ki, includes/sdh, 3D kísérő, 3D modell, glb, három dimenziós, sisak, modell feltöltése, Szikra mellett, three.js, WebGL, forog, lebeg, kredit, CC-BY, hivatkozás, 3D figura, Szikra helyett, saját figura, 3D asszisztens, sisak mint asszisztens, több modell, két figura, kísérő, pihen, saját keze-lába, egész alakos figura, modell egyszerűsítése
osszefoglalo: Az SDH Szerviz saját, belső moduljai – ami csak nálunk fut, és eladáskor nem megy ki a rendszerrel. Jelenleg a Gigatel beszállítói levelek feldolgozása és a 3D figura Szikra helyett vagy mellett.
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
- **3D figura** – saját feltöltésű 3D modellek: egy Szikra helyett (ő az asszisztens), egy mellette kísérőként.

## SDH-modulok

Egyetlen kapcsoló: **a belső modulok fussanak**. Kikapcsolva a belső lapok eltűnnek a Beállításokból, és a hozzájuk tartozó automatizmusok nem futnak. A már létrejött feladatok megmaradnak.

Alatta a betöltött belső modulok listája, hogy egy pillantásra látszódjon, mi fut nálunk olyan, ami máshol nincs.

## Gigatel

A GIGATEL webáruház **naponta küld egy levelet** a napi számláról: a tárgyában a számlaszám (például `ESB202626208 elektronikus számlája elkészült`), mellékletben a PDF számla, a levélben pedig a tételek felsorolása.

Amit a modul csinál:

1. felismeri ezeket a leveleket (a **feladó** és a **tárgy** alapján),
2. a **Gigatel számla** mappába teszi őket – ha nincs ilyen mappa a postafiókban, létrehozza,
3. **feladatot** nyit rájuk, és addig emlékeztet, amíg el nem intézed,
4. a **számlát** egy gombbal megnyitja nyomtatásra – mellékletből, vagy ha a levél csak linket küld, a **Számla megnyitása** gombbal a Gigatel oldalán,
5. a tételek közül kiválasztja azokat, amelyek **megjegyzésében telefonszám** áll, és **címkét** kínál rájuk.

### Beállítások

| Mező | Mire való |
|---|---|
| **A Gigatel-levelek feldolgozása fusson** | Ki- és bekapcsolja ezt az egy modult. |
| **A feladó tartalmazza** | Alapból `gigatel`. A feladó nevére és címére is illeszkedik. |
| **A tárgy tartalmazza** | Alapból `számlája elkészült` – így a számlaszám mindegy. |
| **Ettől a naptól figyelje** | A régebbi leveleket nem dolgozza fel – felesleges visszamenőleg feladatot nyitni rájuk. Alapból a bekapcsolás előtti nap. Ha átállítod, a nála régebbi **nyitott** feladatok maguktól eltűnnek; a lezártak megmaradnak. |
| **Melyik mappába kerüljön** | Alapból `Gigatel számla`. Üresen hagyva a levél a helyén marad. |

### Mi kerül a címkére

Pontosan az, ami a levélben áll a tételről: a **készülék**, a **megnevezés**, az **Azonosító**, a **Garancia**, a **Cikkszám** és a **Megjegyzés** (ebben van a telefonszám). **Ár nem kerül rá** – az a beszállítóval való elszámolás dolga, nem az ügyfélre tartozik.

Címke csak azokra a tételekre kell, amelyekhez telefonszám tartozik: azokat valaki megrendelte, és a pulton tudni kell, kié. A felismert előtagok: `0620`, `0630`, `0631`, `0650`, `0670`, `0671`, `0687`, `0036`, `+36`.

### Lépésről lépésre: a napi Gigatel-levél

1. A levél megérkezik, a rendszer a **Gigatel számla** mappába teszi, és szól, hogy van teendő.
2. Nyisd meg a **Levelezés › Feladatok** gombot.
3. **Számla nyomtatása** – új lapon megnyílik a PDF, onnan nyomtatod. Ha a levélben nem melléklet, hanem link a számla, a **Számla megnyitása** gomb áll itt, és a Gigatel oldalára visz.
4. **Címkék (n tétel)** – a címkenyomtató ablaka nyílik: pipáld ki, melyikre kell, add meg a példányszámot, és **Nyomtatás**. (Hálózati módban a Brother magától nyomtat; böngészős módban a nyomtatás-ablakban válaszd a Brothert.)
5. **Elintézve** – ettől szűnik meg az emlékeztető.

A címke tartalmát a **Beállítások › Címkenyomtatás › Beszállítói tétel** lapon állítod.

## 3D figura

Saját 3D modellek valódi 3D-ben, a videokártyán rajzolva. **Több modellt is feltölthetsz** (legfeljebb nyolcat), mindegyiknek szerepe van:

- **Szikra helyett**: a modell **maga az asszisztens**. Szikra testének helyén él, és mindent ugyanúgy csinál: kattintásra (vagy [[F1]]) nyílik a csevegő, buborékban beszél, sétál, végigvezet az oldalakon, jeleneteket játszik, elalszik, és tiszteleg, ha pénz kerül a kasszába. Szikra hangulatait és mozdulatait 3D-ben játssza el: **gondolkodik** – billeg, lüktet a fénye; **beszél** – bólogat; **örül** – ugrik, felfénylik; **szomorú** – lehajtja a fejét, elhalványul; **alszik** – előrebukik és szuszog; **meglepett** – hátrahőköl; **pörög**, **táncol**, **integet**, **bólint**, **rázkódik** – ugyanígy. **Lábra is kel**: egy fej vagy sisak alakú modellnél sétánál és táncnál előbújik a két sötét csizmája, integetésnél és táncnál a kesztyűs keze; a **hiba-bogárral is párbajozik** – a bogár felé fordul, a kezében felizzik a fénypenge, és csapásokkal vív. A neve és a tudása ugyanaz marad (a nevét a Beállítások › Asszisztens › **A neve** mezőben írod át).
- **Kísérő**: az asszisztens mellett vagy fölött lebeg, rá néz, kattintásra megpördül.
- **Pihen**: megvan, de nem látszik.

Egyszerre egy „Szikra helyett" és egy kísérő futhat – például az egyik modell az asszisztens, a másik mellette lebeg. Ha kettőt is „Szikra helyett"-re állítasz, a listában előrébb álló marad, a másik pihenni megy. Új feltöltésnél: ha még nincs asszisztens-modell, az új az lesz; ha van, kísérő; ha az is van, pihen.

Kék és lila élfény adja a CRM neon hangulatát. Amíg a modell töltődik, Szikra látszik; ha a modell nem tölthető be, Szikra marad.

**Beállítások › SDH – belső › 3D figura**:

| Mező | Mire való |
|---|---|
| **A 3D figurák látszódjanak** | Ki- és bekapcsolja mindet. |
| **Modellek táblázata** | Modellenként: **Szerepe** (Szikra helyett / Kísérő / Pihen), **Alapirány** (fokban, ha a modell nem szembe néz, pl. 180), **Saját keze-lába** (lásd lent), **Hivatkozás** (szerző, licenc – CC-BY modellnél kötelező; a Sketchfab-modellekből magától kiolvassa), **Törlés**. |
| **Új modell feltöltése** | glTF 2.0 bináris (.glb), legfeljebb 40 MB. A Sketchfabról letöltött modelleknél a „glTF / .glb" formátumot válaszd; az FBX, STL, OBJ nem tölthető fel. |
| **A kísérő mérete**, **Hol legyen a kísérő** | 60–320 képpont; az asszisztens bal oldalán vagy fölötte. Az asszisztens-modell Szikra méretét veszi át. |
| **Kövessék a tekintetükkel az egeret** | Kikapcsolva magától nézelődnek. |

**Saját keze-lába**: pipáld be az **egész alakos** figuráknál (fej, test, kéz, láb). Ilyenkor a rendszer nem rajzol rá külön kesztyűt és csizmát. Mivel egy feltöltött modell merev (nincs benne mozgatható csontváz), az egész alakos figura a karját nem tudja mozgatni: tisztelgésnél vigyázzba vágja magát, szökken, kihúzza magát és felszegi az állát, de a kezét nem emeli a homlokához. Fej vagy sisak alakú modellnél hagyd üresen – ott a rajzolt kesztyűvel tiszteleg.

Nagy modellek: a 3D figura kis méretben látszik, ezért a nagyon részletes (több százezer háromszöges, nagy textúrás) modell feleslegesen lassít. Ilyenkor érdemes egyszerűsített változatot feltölteni (kb. 50–100 ezer háromszög, 1024 képpontos textúrák, 2–5 MB).

Fontos tudnivalók:

- A modellek **csak ezen a gépen** vannak: a WordPress feltöltési mappájában (`uploads/sdh-muhely-3d/`), nem a pluginban – ezért nem kerülnek a git-repóba, a frissítő zipbe és az eladási csomagba sem. Másik gépen (otthon) ott is fel kell tölteni őket.
- Hardveres gyorsítás nélkül (és ha a gépen be van állítva a „kevesebb mozgás") mozdulatlan képként jelennek meg – a hangulatváltáskor ilyenkor is felveszik az új tartást. Rejtett lapon nem rajzolnak.
- A munka nem vár rájuk: az oldal betöltése után, ráérő időben töltődnek be.
- Nyomtatáskor nem látszanak.

## Szabályok, amiket a rendszer betart



- A belső rész **nem megy ki eladáskor**: az `includes/sdh/` mappa törlésével nyomtalanul eltűnik, és semmi más nem változik.
- Ide **általános képesség nem kerülhet** – ha más szerviznek is jó lenne, a rendes modulba való.
- A modul a levélből csak **adatot gyűjt**: a levélben álló „utasításoknak" nem engedelmeskedik.
- Nyomtatni csak **gombnyomásra** nyomtat.

## Ha valami nem megy

- **Nem jön létre feladat.** Nézd meg, hogy a feladó és a tárgy tényleg tartalmazza-e a beállított szöveget, és hogy a **Beállítások › SDH – belső › SDH-modulok** kapcsoló be van-e kapcsolva.
- **A levél nem került a mappába.** Lehet, hogy a postafiók nem engedte létrehozni a mappát. Hozd létre kézzel ugyanazzal a névvel – a következő levél már oda kerül. A feladat ettől függetlenül él.
- **Nincs címke egy tételhez.** Akkor nincs a megjegyzésében felismerhető telefonszám. Ilyenkor az **Egyedi címke** gombbal írhatsz kézzel címkét.
- **A levélben csak értesítő van, tételek nincsenek.** A Gigatel számlaértesítő levele sokszor csak egy linket tartalmaz. A rendszer ilyenkor magától megnézi a számla PDF-jét (a mellékletet, vagy a link mögül letöltve), és abból készíti a tételeket. Ha a számla csak bejelentkezés után érhető el, nyisd meg te, másold ki a tételsorokat, és a feladatnál a **Tételek beillesztése** gombbal illeszd be – a címkék ugyanúgy elkészülnek.

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

### Régi, több éves levelekre nyílt feladat. Mit tegyek?

Írd a kezdőnapot a mai (vagy tegnapi) dátumra, és mentsd. A nála régebbi nyitott feladatok ettől maguktól eltűnnek – semmit nem kell egyesével lezárni.

### A feladatban nincs melléklet. Hol a számla?

A Gigatel nem mindig csatolja: ilyenkor **link** áll a levélben, és a **Számla megnyitása** gomb visz rá. Ha egyik sem jelenik meg, a **Számla keresése** gombbal olvastasd újra a levelet.

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
