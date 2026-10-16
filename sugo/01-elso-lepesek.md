---
kulcs: elso-lepesek
cim: Első lépések
csoport: Kezdés
modul:
szakaszok:
kulcsszavak: kezdés, első lépések, belépés, bejelentkezés, menü, oldalmenü, fejléc, kereső, keresés, profil, profilmenü, popup, felugró ablak, ablak, teljes képernyő, lapozás, másolás, dátum, naptár, jelvény, gyorsbillentyű, billentyűparancs, betűméret, sötét mód, papír mód, kékfény, első nap
osszefoglalo: Mi ez a rendszer, hogyan lépsz be, hogyan épül fel a felület, és milyen sorrendben érdemes elindulni az első napon.
---

## Mire való

Ez egy szerviz belső műhely- és ügyfélkezelő rendszere. Egy helyen van benne minden, ami a napi munkához kell: az ügyfelek, a készülékeik, a munkalapok, a pultos eladás, a rendelések, a napi kassza, a számlázás, az ügyfelekkel váltott üzenetek, a levelezés, a naptár és a kollégák közötti üzenetek.

A rendszer böngészőben fut, telepíteni a gépedre semmit nem kell. Teljes képernyős, saját felülete van: bal oldalt a menü, felül a fejléc, középen az, amivel éppen dolgozol.

Kinek szól:

- **A pultnál dolgozónak:** készülék átvétele, munkalap nyitása, fizetés, kiadás.
- **A szerelőnek:** a rá váró munkalapok, a hibák és a javítás rögzítése, állapotváltás.
- **Az adminisztrátornak:** mindez, és még a [Beállítások](sugo:beallitasok), a kollégák kezelése és a mentések.

Belépés nélkül a rendszerből semmi nem látszik: belső adatokat mutat, ezért csak bejelentkezett, jogosult fiók éri el.

## Belépés a rendszerbe

1. Nyisd meg a böngészőben a műhely címét. Ezt az adminisztrátortól kapod, például `muhely.pelda.hu/muhely/`.
2. Ha még nem vagy bejelentkezve, a bejelentkező oldal jelenik meg. Írd be a felhasználónevedet és a jelszavadat.
3. Belépés után az **Áttekintés** nyílik meg – vagy az az oldal, amelyet eredetileg megnyitottál.

Kilépni a jobb felső sarokban, a nevedre kattintva, a **Kijelentkezés** sorral tudsz.

Ha belépés után az **Ehhez nincs jogosultságod.** oldalt látod, a fiókod létezik, de nem fér hozzá a műhelyrendszerhez. Szólj az adminisztrátornak. Ha a bejelentkező oldal azt írja, **Ez a fiók le van tiltva. Szólj az adminisztrátornak.**, akkor a fiókodat letiltották.

> **Tipp:** Tedd a műhely címét a böngésző könyvjelzői közé, vagy állítsd be kezdőlapnak a munkahelyi gépen.

## A bal oldali menü

A bal oldali sávban van minden menüpont. Legfelül a logó és a rendszer neve áll – rákattintva mindig az **Áttekintésre** jutsz.

- **Áttekintés** – a kezdőképernyő: csempék és az összes munkalap táblázata. Részletek: [Áttekintés](sugo:attekintes).
- A **Nyilvántartás** felirat alatt a többi menüpont: **Ügyfelek**, **Eszközök**, **Munkalapok**, **Pénztár**, **Napi árukiadás**, **Rendelések**, **Üzenetek**, **Levelezés**, **Naptár**, **Csapat**, **Szolgáltatások**, **Termékek** és a **Súgó**.
- Legalul a **Beállítások** – ezt csak az adminisztrátor látja.

Az éppen megnyitott menüpont kiemelve látszik. Ha a cég egy közös fiókkal dolgozik, a **Csapat** helyén az üzenőfal neve áll (alapból **Üzenőfal**).

### A menü összecsukása

A fejléc bal szélén a három vízszintes vonalból álló gomb összecsukja a menüt, hogy több hely maradjon a tartalomnak. Újabb kattintásra visszanyílik. A böngésző megjegyzi, hogyan hagytad.

## A felső fejléc

A fejléc minden oldalon ugyanaz. Balról jobbra:

- **Menügomb** – a bal oldali menü összecsukása és kinyitása.
- **Morzsa** – megmutatja, hol vagy: a rendszer neve, utána az aktuális oldal. A rendszer nevére kattintva az Áttekintésre jutsz.
- **Mindenhol-kereső** – az **Ügyfél, telefon, IMEI, gyári szám…** feliratú mező. Lásd lejjebb.
- **Óra** – neon üveg-hatású; a pontos idő, a mai dátum, és hogy mennyi idő van zárásig (például *Zárásig 2 óra 14 perc*). Nyitás előtt *Nyitásig …*, zárás után *Zárva · nyitás: …* áll ott. Ha még nincs megadva nyitvatartás, a **Nyitvatartás megadása** hivatkozás látszik. Az ikon körüli gyűrű mutatja, mennyi telt el a nyitvatartási napból. A nyitvatartást az adminisztrátor állítja be: [Megjelenés](sugo:megjelenes).
- **Profilmenü** – a neved a jobb szélen.

### A mindenhol-kereső

A pultnál ez a leggyorsabb út egy ügyfélhez vagy egy készülékhez.

1. Kattints a keresőbe, vagy nyomd meg a [[/]] billentyűt.
2. Írj be legalább két karaktert: nevet, telefonszámot, e-mail-címet, ügyfélszámot, IMEI-t, gyári számot vagy típust.
3. A találatok két csoportban jelennek meg: **Ügyfelek** és **Eszközök**, csoportonként legfeljebb öt.
4. Kattints a találatra – megnyílik az ügyfél vagy az eszköz.

Ha nincs egyezés, a lista azt írja: **Nincs találat.** A kereső csak az aktív ügyfeleket és eszközöket nézi. Munkalapszámra az **Áttekintés** munkalap-táblázatában keress.

### A profilmenü

A nevedre kattintva nyílik. Amit itt állítasz, az csak a te gépedre, a te böngésződre szól – a kollégákét nem változtatja meg.

- **Megjelenés:** **Rendszer** (a géped beállítását követi), **Világos**, **Sötét** vagy **Papír** – az utóbbi nyugodt, e-könyv-olvasóra emlékeztető felület, hosszú olvasáshoz.
- **Kékfény-szűrő:** csúszka; állítható erősségű melegebb színek, esti munkához.
- **Üvegszín:** a felület kiemelő színe, két sorban: **Duó** (kontrasztos színpár) és **Egyszínű** (egy színcsalád).
- **Betűméret:** csúszka. Húzás közben a csúszka fölötti minta mutatja az új méretet; a felület – minden betű, és vele a gombok, sorok – az elengedéskor vált. A két **A** gomb lépésenként állít, a körkörös nyíl visszaáll az alapméretre.
- **Popup neon keret:** a felugró ablakok fénylő keretének színe, vagy kikapcsolva.
- **Kijelentkezés.**

A cég alapértelmezett megjelenését az adminisztrátor a Beállításokban adja meg: [Megjelenés](sugo:megjelenes).

## Hogyan működnek a popupok

A rendszerben minden felvitel és szerkesztés felugró ablakban, popupban történik: új ügyfél, új eszköz, új munkalap, fizetés, számla. Közben az oldal a háttérben a helyén marad, a lista nem tűnik el.

- **Megnyitás:** az oldal tetején lévő gombbal (például **Új munkalap**), vagy a lista egy elemére kattintva.
- **Mentés:** az ablak alján lévő gombbal. Mentés után az ablak bezárul, a lista frissül, és az oldal tetején megjelenik a visszajelzés (például *Az ügyfél létrejött.*).
- **Bezárás mentés nélkül:** a **Mégsem** gombbal, a piros ablakgombbal, az [[Esc]] billentyűvel, vagy a popup melletti sötét háttérre kattintva.
- **Hiba:** ha valami hiányzik, piros sáv jelenik meg az ablak tetején, benne a teendővel. Az ablak nyitva marad, a beírt adat nem vész el.

> **Figyelem:** A bezárás nem kérdez rá a mentésre. Amit beírtál, de nem mentettél el, az a bezárással elvész.

### Az ablakgombok

A popup bal felső sarkában két kerek gomb áll, ahogy egy asztali ablakon:

- **Piros** – **Bezárás**.
- **Zöld** – **Teljes képernyő**. Újabb kattintásra **Vissza ablakméretre**.

### Méretezés

- Fogd meg az ablak bármelyik szélét vagy sarkát, és húzd. A popup középen marad, a szemközti széle együtt mozdul.
- Dupla kattintás az ablak címén: teljes képernyő be vagy ki.
- Dupla kattintás az ablak szélén: vissza az alapméretre.

A méretet a böngésző ablakfajtánként megjegyzi: ha a munkalap ablakát nagyobbra húzod, legközelebb is úgy nyílik, az ügyfélé pedig marad, amilyen volt.

### Görgetősáv helyett kicsinyítés

A popupban nincs görgetősáv: a felvitel mindig egy képernyőn van, nem kell keresgélni, hol a mentés gomb. Ha a tartalom magasabb, mint a képernyő, az ablak arányosan kisebb lesz, hogy minden egyszerre látszódjon. Ha így túl apró a betű: tedd teljes képernyőre a zöld ablakgombbal, vagy állítsd az ablakok betűjét a **Beállítások › Logó és megjelenés › Betű területenként** dobozban – ott az „Űrlapok és popup ablakok" sora szól erről.

### Ablak az ablak fölött

Ha munkalap felvitele közben derül ki, hogy az ügyfél még nincs a rendszerben, nem kell kilépned. Az ügyfélmező melletti **+** gomb (**Új ügyfél felvétele**) egy második ablakot nyit az első fölött. Mentés után az új ügyfél rögtön bekerül a munkalapba, és a félig kitöltött munkalap érintetlen marad. Ugyanígy működik az **Új eszköz felvétele** is.

## Listák: keresés, szűrés, lapozás

A menüpontok többsége egy listával nyit: ügyfelek, eszközök, munkalapok, termékek.

- **Keresés:** a lista fölötti mezőbe írd be, amit keresel, és nyomd meg a **Keresés** gombot vagy az [[Enter]] billentyűt.
- **Szűrés:** ahol van szűrő (például kategória vagy állapot), a kiválasztása azonnal szűkíti a listát.
- **Szűrő törlése:** visszaadja a teljes listát.
- **Lapozás:** a hosszú lista oldalakra van bontva, a lista alján számozott lapozóval. A rendszer mindenhol lapoz, nem görget – így a fejléc és a gombok mindig szem előtt maradnak.
- **Megnyitás:** a sorban a névre (a kiemelt hivatkozásra) kattintva a szerkesztő popup nyílik meg.

Az egyes listák saját lehetőségeiről (rendezés, nézetek, oszlopok) a menüpont saját fejezete ír.

## Másoló gombok az adatok mellett

Sok adat mellett – IMEI, név, cím, telefonszám – egy kis, két lapot ábrázoló ikon áll. Rákattintva az adat a vágólapra kerül, és máshol a [[Ctrl]]+[[V]] billentyűkkel beillesztheted.

A sikeres másolást pipa jelzi, a gomb fölé vitt egérnél a **Kimásolva** felirat látszik. Ha a böngésző nem engedte a másolást, a gomb azt írja: **Nem sikerült kimásolni – jelöld ki, és Ctrl+C**.

## Dátumválasztó

A dátummezők (például **Készült**, **Határidő**) a rendszer saját naptárát nyitják meg, nem a böngészőét – így minden gépen ugyanúgy néz ki.

1. Kattints a dátummezőre. Billentyűzetről az [[Enter]], a [[Szóköz]] vagy a [[↓]] nyitja.
2. A két nyíllal lépj hónapot (**Előző hónap**, **Következő hónap**).
3. Kattints a napra.

A **Ma** gomb a mai napot írja be, a **Törlés** kiüríti a mezőt. A kijelölt mezőben a [[Backspace]] vagy a [[Delete]] is töröl.

## Menüjelvények

Néhány menüpont mellett kis szám vagy jelzés áll. Ez azt mutatja, hogy ott tennivaló vár:

| Menüpont | Mit jelez |
|---|---|
| **Üzenetek** | olvasatlan ügyfélüzenetek száma |
| **Levelezés** | új levél érkezett; több postafióknál alatta fiókonként egy sor |
| **Csapat** | olvasatlan belső üzenetek száma |
| **Naptár** | esedékes esemény, amelynek az értesítése még nincs kinyomva |
| **Rendelések** | megérkezett áru, amelyről az ügyfél még nem tud |

A jelvény magától frissül, nem kell újratölteni az oldalt. Ezer fölött **999+** áll rajta. Ha nincs tennivaló, a jelvény eltűnik.

## Az első nap teendői sorrendben

Új rendszernél ebben a sorrendben érdemes haladni. Az első lépés az adminisztrátoré, a többi mindenkié.

1. **Beállítások.** Add meg a cég nevét és logóját, a nyitvatartást, a munkalapszám kezdőértékét, a fizetési módokat és a számlázás adatait. Ha több kolléga dolgozik, vedd fel őket. Fejezetek: [Beállítások](sugo:beallitasok), [Megjelenés](sugo:megjelenes), [Csapat](sugo:csapat).
2. **Szolgáltatások és termékek.** Vedd fel a leggyakoribb javításokat és az eladott alkatrészeket, tartozékokat az árukkal. Így a munkalapon már csak választani kell. Fejezetek: [Szolgáltatások](sugo:szolgaltatasok), [Termékek](sugo:termekek).
3. **Ügyfél.** Az **Áttekintésen** nyomd meg az **Új ügyfél** gombot. Csak a név kötelező. Fejezet: [Ügyfelek](sugo:ugyfelek).
4. **Eszköz.** Vedd fel az ügyfél készülékét. Az eszköz mindig egy ügyfélhez tartozik. Fejezet: [Eszközök](sugo:eszkozok).
5. **Munkalap.** Az **Új munkalap** gombbal nyiss lapot: válaszd ki az ügyfelet és az eszközt, írd be a hibát. Fejezet: [Munkalapok](sugo:munkalapok).
6. **Fizetés.** Amikor az ügyfél fizet, a munkalapról rögzítsd a fizetést, és ha kell, állíts ki számlát. Fejezetek: [Pénztár](sugo:penztar), [Számlázás](sugo:szamlazas).
7. **Zárás.** Nap végén számold meg a kasszát, és zárd le a napot a **Pénztár** menüpontban. Fejezet: [Pénztár](sugo:penztar).

Amire a következő napokban lesz szükséged:

- Pultos eladás javítás nélkül: [Napi árukiadás](sugo:arukiadas).
- Ügyfélnek rendelt áru: [Rendelések](sugo:rendelesek).
- Üzenetváltás az ügyféllel a munkalapról: [Üzenetek és ügyféloldal](sugo:uzenetek-rma).
- A cég e-mail-fiókjai a rendszerben: [Levelezés](sugo:levelezes).
- Időpontok, emlékeztetők: [Naptár](sugo:naptar).
- A beépített segítő: [Asszisztens](sugo:asszisztens).
- Mentés és jogosultságok: [Mentés, jogosultság, biztonság](sugo:mentes-biztonsag).
- Ha elakadsz: [Hibaelhárítás](sugo:hibaelharitas).

> **Tipp:** Ha csak kipróbálnád a rendszert, az adminisztrátor egy gombbal betölthet kitalált ügyfeleket, készülékeket és munkalapokat. Éles rendszerbe ez nem való – lásd: [Beállítások](sugo:beallitasok).

## Segítség munka közben

Háromféle segítség van kéznél, bármelyik oldalon:

- **Súgó menüpont.** Négy része van: **Bemutató**, **Dokumentáció** (ezek a fejezetek), **Kérdés–válasz** és **Újdonságok**, fölöttük keresővel. A fejléc kérdőjeles gombja annak az oldalnak a fejezetét nyitja meg, ahol éppen vagy. Fejezet: [A súgó használata](sugo:sugo).
- **Az asszisztens.** A jobb alsó sarokban élő kis figura. Kattints rá, vagy nyomd meg az [[F1]] billentyűt, és kérdezz a saját szavaiddal. A **Vezess végig ezen az oldalon** kérésre több lépésben, sorban megmutatja az oldal részeit.
- **Hibánál magától szól.** Ha egy ablak hibaüzenetet ad, az asszisztens felajánlja a segítségét a **Segíts** gombbal.

Az asszisztens adatot csak a te jóváhagyásoddal módosít. Részletek: [Asszisztens](sugo:asszisztens).

## Billentyűparancsok

| Billentyű | Mit csinál |
|---|---|
| [[/]] | A mindenhol-keresőbe ugrik. Csak akkor, ha éppen nem egy mezőbe írsz. |
| [[F1]] | Megnyitja az asszisztenst. |
| [[Esc]] | Bezárja a popupot, a kereső találati listáját vagy az asszisztens ablakát. |
| [[Enter]] | Keresőmezőben keres; ügyfél- vagy eszközválasztó mezőben az első találatot választja. |
| [[↓]] [[↑]] | A választólistákban és az irányítószám-listában lép a találatok között; az [[Enter]] választ. |
| [[Page Down]] [[Page Up]] | A szolgáltatás- és a termékválasztóban egy oldalnyit lép. |
| [[↓]] | A munkalap tételsorának megnevezés mezőjében megnyitja a választót. |
| [[Enter]], [[Szóköz]] | Kijelölt dátum- vagy választómezőn megnyitja a választót. |
| [[Backspace]], [[Delete]] | Kijelölt dátum- vagy választómezőn kiüríti a mezőt. |
| [[Shift]]+[[Enter]] | Üzenet írásakor új sort kezd; a sima [[Enter]] elküldi az üzenetet. |
| [[Ctrl]]+[[S]] | A Beállításokban elmenti az éppen nyitott lapfület (Macen [[Cmd]]+[[S]]). |

## Szabályok, amiket a rendszer betart

- **Belépés nélkül semmi nem látszik.** A be nem jelentkezett látogató a bejelentkező oldalra kerül.
- **A Beállításokat csak az adminisztrátor éri el.** A kolléga menüjében a menüpont meg sem jelenik.
- **Minden felvitel popupban történik,** és csak a mentés gombbal kerül a rendszerbe.
- **A saját megjelenésed a saját gépedé.** A téma, a szín, a betűméret, a menü állapota és az ablakméretek a böngészőben tárolódnak, a kollégákét nem érintik.
- **Ügyfelet és munkalapot a rendszer nem töröl.** Az ügyfél inaktívvá tehető, a téves munkalap érvénytelen állapotba kerül. Lásd: [Mentés, jogosultság, biztonság](sugo:mentes-biztonsag).
- **A felület frissítés után magától megújul.** Ha a rendszer új verziót kapott, a nyitva hagyott oldal egyszer újratöltődik.

## Tippek

- A [[/]] billentyűvel soha nem kell az egérért nyúlnod, ha ügyfelet keresel.
- A munkalap ablakát érdemes egyszer nagyobbra húzni vagy teljes képernyőre tenni – a rendszer megjegyzi.
- Az IMEI-t ne gépeld át másik programba: használd a mellette álló másoló gombot.
- Ha a pultnál több gépen dolgoztok, a megjelenést gépenként külön állíthatjátok be.
- Ha kevés a hely, csukd össze a menüt, az Áttekintésen pedig rejtsd el a csempéket a **Csempék elrejtése** gombbal.

## Ha valami nem megy

- **Nem tölt be az oldal, vagy „Nincs ilyen oldal" jelenik meg:** nézd meg a címet, vagy válassz a bal oldali menüből.
- **Az űrlap nem töltődött be:** zárd be a popupot, töltsd újra az oldalt az [[F5]] billentyűvel, és próbáld újra.
- **Nem látod a Beállításokat:** kollégaként léptél be, a Beállítások az adminisztrátoré.
- **Minden túl kicsi vagy túl nagy:** a profilmenüben a **Betűméret** csúszkán a körkörös nyíl visszaállítja az alapméretet.

A gyakori elakadások részletes listája: [Hibaelhárítás](sugo:hibaelharitas).

## GYIK

### Hol találom a rendszert, mi a címe?

A címet az adminisztrátortól kapod. A cég belső rendszerének címe, a végén `/muhely/` – például `muhely.pelda.hu/muhely/`. Érdemes könyvjelzőbe tenni.

### Elfelejtettem a jelszavamat, mit csináljak?

Szólj az adminisztrátornak. Ő a **Csapat** menüpontban, a kollégák között új jelszót tud adni neked.

### Miért nem látom a Beállítások menüpontot?

Mert kollégaként léptél be. A Beállításokat csak az adminisztrátor látja és nyithatja meg.

### Hogyan keresek rá gyorsan egy ügyfélre?

Nyomd meg a [[/]] billentyűt, és kezdd el írni a nevét vagy a telefonszámát. Két karakter után megjelennek a találatok, kattints a megfelelőre.

### Miért nem találja a kereső a munkalapszámot?

A fejléc keresője ügyfelet és eszközt keres. Munkalapszámra az **Áttekintés** munkalap-táblázatában keress, vagy a **Munkalapok** menüpontban.

### Bezártam a popupot, elveszett, amit beírtam?

Igen, ha nem mentetted el. A bezárás nem kérdez rá. Mindig a mentés gombbal zárd le a munkát.

### Hogyan teszem nagyobbra a felugró ablakot?

Húzd a szélét vagy a sarkát, vagy nyomd meg a bal felső sarokban a zöld gombot a teljes képernyőhöz. A rendszer megjegyzi a méretet.

### Miért lett apró betűs az ablak?

Mert a tartalom nem fért ki a képernyőn, és a rendszer görgetés helyett lekicsinyítette – így nem kell görgetned a mentés gombig. Tedd teljes képernyőre, vagy a **Beállítások › Logó és megjelenés › Betű területenként** dobozban add nagyobbra az „Űrlapok és popup ablakok" betűjét (a többi rész mérete változatlan marad).

### Hogyan állítom vissza az ablak eredeti méretét?

Kattints duplán az ablak bármelyik szélére. Ha teljes képernyőn van, előbb a zöld gombbal lépj vissza ablakméretre.

### Hol állítom át sötét módra?

A jobb felső sarokban kattints a nevedre, és a **Megjelenés** sorban válaszd a **Sötét** lehetőséget. Ez csak a te gépedre szól.

### Mire jó a Papír mód és a Kékfény-szűrő?

A **Papír** nyugodt, kevés színt használó felület, hosszú olvasáshoz. A **Kékfény-szűrő** melegebbé teszi a színeket, az erőssége állítható. Mindkettő a profilmenüben van.

### Túl kicsik a betűk, lehet nagyítani?

Igen. A profilmenüben a **Betűméret** csúszkával 80 és 160 százalék között állíthatod. A beállítás a gépeden megmarad.

### Mit jelent a szám a menüpont mellett?

Azt, hogy ott tennivaló vár: olvasatlan üzenet, új levél, esedékes naptáresemény vagy megérkezett rendelés. Ha elintézted, a jelvény eltűnik.

### Mit mutat az óra a fejlécben?

A pontos időt, a mai dátumot, és azt, mennyi idő van még zárásig. A nyitvatartást az adminisztrátor adja meg a Beállításokban.

### Lehet a menüt elrejteni, hogy több hely legyen?

Igen. A fejléc bal szélén a háromvonalas gomb összecsukja, újabb kattintásra visszanyílik.

### Hogyan másolok ki egy IMEI-t?

Kattints az IMEI melletti kis másoló ikonra. A szám a vágólapra kerül, és a [[Ctrl]]+[[V]] billentyűkkel bárhová beillesztheted.

### Miért töltődött újra magától az oldal?

Mert a rendszer közben új verziót kapott. A nyitva hagyott oldal ilyenkor egyszer magától frissül, hogy az új felülettel dolgozz. Teendőd nincs vele.

### Kit kérdezzek, ha elakadok?

Nyomd meg az [[F1]] billentyűt, és kérdezd meg az asszisztenst. Ha egy egész oldalt szeretnél megismerni, írd be neki: *Vezess végig ezen az oldalon*. A **Súgó** menüpontban a teljes dokumentáció is olvasható.

### Elront valamit, ha csak nézelődöm?

Nem. Amíg nem nyomsz mentés gombot, semmi nem változik. Ügyfelet és munkalapot a rendszer egyébként sem töröl.

### Milyen sorrendben kezdjem a munkát az első napon?

Beállítások, aztán ügyfél, eszköz, munkalap, fizetés, és nap végén zárás. A részleteket *Az első nap teendői sorrendben* rész írja le.
