---
kulcs: termekek
cim: Termékek
csoport: Napi munka
modul: termekek
szakaszok:
kulcsszavak: rendelési javaslat, rendelés beszállítótól, foglalás, foglalt, lefoglalt alkatrész, célkészlet, megérkezett, hiány, nincs raktáron, CSV, termék, alkatrész, áru, cikk, készlet, raktár, nyitókészlet, bevételezés, korrekció, leltár, készletmozgás, tételmozgás, beszerzési ár, eladási ár, haszonkulcs, árrés, cikkszám, termékkód, vonalkód, gyári szám, beszállító, minimális készlet, rendelni kell, inaktív
osszefoglalo: A Termékek menüpont az alkatrészek és eladható áruk törzse készlettel, beszerzési és eladási árral; a munkalapra vagy árukiadásra tett termék magától lejön a készletről.
---

## Mire való

A **Termékek** menüpont a terméktörzs és a készletnyilvántartás. Itt van minden alkatrész és eladható áru: kijelzők, akkumulátorok, tartozékok, műhelyanyagok.

Egy terméknél nyilvántartod a kódjait (gyári szám, cikkszám, termékkód, vonalkód), a kategóriáját, a beszállítóját, a beszerzési és az eladási árát, és a készletét.

A készletet nem neked kell fejben tartanod. Amit a munkalapra vagy a napi árukiadásra termékként felteszel, az mentéskor magától lejön a készletről. Amit beveszel vagy leltárban javítasz, az az űrlapon rögzül. Minden változás visszakereshető a készletmozgások között.

## Hol találod

- Az oldalmenüben a **Termékek** pontra kattintva: [Termékek megnyitása](modul:termekek).
- Az Áttekintésen az **Új termék** gombbal.
- A munkalap és a napi árukiadás **Termékek** lapfülén a termékválasztó ablakból: ott is felvehetsz és szerkeszthetsz terméket.

A Termékek oldal tetején nézetfülek állnak: **Összes termék**, **Készleten**, **Rendelni kell**, **Rendelési javaslat**, **Inaktív** és **Összes tételmozgás**. Az **Inaktív** fül csak akkor látszik, ha van inaktív termék.

## Lépésről lépésre: új termék felvétele

1. A Termékek menüpontban kattints az **Új termék** gombra.
2. Írd be a **Megnevezés** mezőt. **Csak ez kötelező.**
3. Kattints a **Kategória** mezőre, és válassz a használt vagy a javasolt kategóriák közül, vagy írj be újat.
4. Kattints a **Beszállító** mezőre, és válassz, vagy írj be újat. A választó az eddigi beszállítókat és az ügyfelek között szereplő cégeket kínálja.
5. A **Készletkezelés** jelölő alapból be van pipálva. Írd be a **Nyitókészlet** mezőbe, mennyi van most a polcon.
6. Állítsd be a **M.e.** (mennyiségi egység) mezőt; az alap `db`.
7. Ha szeretnél figyelmeztetést a fogyásra, írd be a **Min. készlet** értékét.
8. Töltsd ki az árakat: **Beszerzési ár** és **Eladási ár**. Elég az egyik oszlopot beírni, a másik kiszámolódik.
9. A **Kódok** dobozban add meg a cikkszámot, a termékkódot, a vonalkódot – amelyik van.
10. Kattints a **Termék létrehozása** gombra.

A nyitókészlet készletmozgásként rögzül, „Nyitókészlet" néven.

> **Példa:** Megnevezés: Kijelző kerettel, fekete. Kategória: Kijelző. Nyitókészlet: 3. Min. készlet: 1. Beszerzési ár nettó: 10 000 Ft. Eladási ár bruttó: 19 000 Ft.

## Lépésről lépésre: árak és haszonkulcs beállítása

A termék űrlapján az árak táblázata élőben számol. Négy oszlopa van: **Nettó**, **Bruttó**, **Áfakulcs**, **Haszonkulcs**.

1. A **Beszerzési ár** sorban írd be a nettó vagy a bruttó árat. A másik az áfakulcsból kiszámolódik.
2. Az **Eladási ár** sorban ugyanígy: írd be a nettót vagy a bruttót.
3. A **Haszonkulcs** mező magától megmutatja, hány százalék a haszon a beszerzési árhoz képest.
4. Fordítva is megy: írd át a haszonkulcsot, és a rendszer kiszámolja belőle az eladási árat.
5. A táblázat alatt ezt látod: „Haszon darabonként", forintban, nettóban.
6. A **Kedvezmény** mezőbe írt százalékkal kerül a termék a munkalapra.

Tudnivalók:

- A haszonkulcs képlete: az eladási nettó és a beszerzési nettó különbsége, osztva a beszerzési nettóval.
- A haszonkulcs csak akkor látszik, ha van beszerzési ár.
- Ha az eladás áfakulcsát váltod, a bruttó ár marad, a nettó igazodik hozzá.
- A beszerzésnek és az eladásnak külön áfakulcsa van.

## Lépésről lépésre: bevételezés és készletkorrekció

A készletet a termék űrlapján, a **Készlet** mező átírásával módosítod.

1. A Termékek listában kattints a termék megnevezésére.
2. Írd át a **Készlet** mezőt arra az értékre, amennyi most valóban van.
3. Amint az érték eltér az eredetitől, megjelenik **A változás oka** mező. Írd be, miért változott: beérkezett rendelés, leltár, selejt.
4. Kattints a **Mentés** gombra.

A rendszer a régi és az új érték különbségét rögzíti mozgásként:

| Mit tettél | Milyen mozgás lesz |
|---|---|
| Új terméknél beírtad a készletet | Nyitókészlet |
| Növelted a készletet | Bevételezés |
| Csökkentetted a készletet | Korrekció |

> **Példa:** A készlet 3 volt, érkezett 5 darab. A **Készlet** mezőbe 8-at írsz, az okhoz: beérkezett rendelés. A rendszer +5 darab Bevételezést rögzít.

## Lépésről lépésre: termék felvétele a munkalapra

A munkalap **Termékek** lapfülén a terméket felugró választóból veszed fel.

1. Nyisd meg a munkalapot, és kattints a **Termékek** lapfülre.
2. Kattints a pluszjeles **Termék** gombra. Megnyílik a *Termék választása* ablak.
3. Kezdd el írni a megnevezést, a cikkszámot, a termékkódot vagy a vonalkódot.
4. Kattints a sorra, vagy lépj rá a [[↑]] [[↓]] nyilakkal, és nyomj [[Enter]] billentyűt.
5. A munkalap sorába bekerül a megnevezés, a termékkód, a cikkszám, a gyári szám, az egység, a bruttó ár, az áfakulcs és a termék kedvezménye. A kurzor a mennyiségre áll.
6. Írd át a mennyiséget, ha nem egy darab.
7. Mentsd a munkalapot. A termék ekkor lejön a készletről.

A választó ablak további lehetőségei:

- **Új termék** – felveszed, ami még nincs a törzsben. Mentés után rögtön a munkalapra kerül.
- **Ceruza a sor végén** – a termék szerkesztése a választó elhagyása nélkül.
- **Kézzel írom be** – üres sort ad. A nevet és az árat te írod be, a készlet ilyenkor nem változik.
- **Mégsem** – bezárja a választót.

A választó minden sorában látod a készletet és a bruttó árat. A lista tíz soronként lapoz.

> **Tipp:** Vonalkódolvasóval a leggyorsabb. Nyisd meg a választót, olvasd be a kódot: a pontos egyezés kerül az első helyre, és az [[Enter]] rögtön a munkalapra teszi.

## Lépésről lépésre: termék eladása a napi árukiadáson

A pultos eladásnál ugyanez a termékválasztó dolgozik.

1. Nyisd meg a napi árukiadást: [Napi árukiadás megnyitása](modul:arukiadas).
2. Az új eladás lapján a **Termékek** lapfül áll elöl.
3. Kattints a **Termék** gombra, és válaszd ki az árut, ahogy a munkalapon.
4. Mentsd az eladást.

Az árukiadás mindig sorszámot kap, ezért a rátett készletkezelt termék mentéskor lejön a készletről. A rendelés lapja is ugyanezt a **Termékek** lapfület és választót használja.

## Lépésről lépésre: készletmozgások megnézése

Egy termék mozgásai:

1. Nyisd meg a terméket.
2. Az alsó lapfülek közül válaszd a **Készletmozgás** fület.
3. Az utolsó hat mozgást látod: időpont, fajta, mennyiség, megjegyzés és ki rögzítette.

Minden termék mozgása egyben:

1. A Termékek oldal tetején kattints az **Összes tételmozgás** nézetfülre.
2. A lista a legújabb mozgással kezdődik, oldalanként 50 sorral.
3. A bevételt pluszjel, a kiadást mínuszjel jelöli.
4. A „Munkalap" fajtájú sorban a szóra kattintva megnyílik az a lap, amelyik a terméket felhasználta. A megjegyzésben a munkalap száma áll.

## Raktár: foglalás és rendelési javaslat

### Foglalás nyitott munkalapon

A munkalapra tett termék rögtön lejön a készletről. Amíg a lap **nyitott** (nincs lezárva), a termék **foglalt**: a javításhoz félre van téve. A termék űrlapján, a készlet alatt látszik: „Foglalva nyitott munkalapokon: 3 db – melyik lapokon”. A lap lezárásakor a foglalás felhasználássá válik.

Ha egy lap terméke miatt a készlet **nulla alá** megy, a munkalap bal oldalán **Raktár – Nincs elég raktáron** jelzés áll a termék nevével.

### Célkészlet

A termék űrlapján a **Min. készlet** mellett a **Célkészlet**: rendeléskor eddig a mennyiségig érdemes feltölteni. Üresen a minimum kétszerese.

### Lépésről lépésre: rendelés a beszállítótól

1. Nyisd meg a **Termékek › Rendelési javaslat** nézetet (a fülön szám jelzi, hány terméket kell rendelni).
2. A termékek **beszállítónként** csoportosítva állnak: készlet, minimum, foglalt, a **javasolt mennyiség** (a célkészletig) és a becsült nettó beszerzési érték.
3. A mennyiség átírható. A **Szöveg a beszállítónak** részben kész rendelési lista van – másold ki, és küldd el.
4. A **Letöltés (CSV, Excelhez)** gombbal az egész javaslat táblázatba menthető.
5. Ha az áru megérkezett, kattints a sor **Megérkezett** gombjára: a rendszer bevételezi a beírt mennyiséget (készletmozgásként, „Rendelés megérkezett” megjegyzéssel).

## Mezők és gombok

### A Termékek lista oszlopai

| Oszlop | Mit mutat |
|---|---|
| Sorsz. | A termék sorszáma |
| Kategória | A termék kategóriája |
| Megnevezés | A név; kattintásra megnyílik a termék |
| Termékkód, Cikkszám, Gyári szám, Vonalkód | A termék kódjai |
| Készlet | A jelenlegi készlet, színes jelzéssel |
| M.e. | Mennyiségi egység |
| Besz. n. ár, Besz. b. ár | Beszerzési nettó és bruttó egységár |
| Áfak. | Az eladás áfakulcsa |
| Nettó ár, Bruttó ár | Eladási nettó és bruttó egységár |
| Bruttó é. | A készlet értéke eladási bruttó áron |

A legtöbb oszlopfejre kattintva rendezel; újabb kattintás megfordítja a sorrendet. Az alap sorrend: a legutóbb felvett termék elöl. Keskeny képernyőn a ritkábban használt oszlopok elrejtőznek.

A lista alján összesítő sor áll a teljes találatra: a készlet összesen, a készlet értéke beszerzési nettó áron és eladási bruttó áron.

### A készlet jelzése

- **Készleten** – van belőle, és nincs a minimum alatt.
- **A minimális készlet alatt** – van, de kevesebb a beállított minimumnál.
- **Nincs készleten** – nulla vagy annál kevesebb.
- **—** – a terméknél nincs készletkezelés.

A pontos szöveget akkor látod, ha az egeret a szám fölé viszed.

### Nézetfülek és szűrők

- **Összes termék** – minden aktív termék.
- **Készleten** – amiből van készlet.
- **Rendelni kell** – ami elfogyott, vagy a minimális készlet alá ment. A fülön szám jelzi, hány ilyen van.
- **Inaktív** – az inaktívra állított termékek.
- **Összes tételmozgás** – a készletmozgások naplója.
- **Keresőmező** – megnevezésre, cikkszámra, termékkódra, gyári számra, vonalkódra, kategóriára, beszállítóra és osztályra keres.
- **Minden kategória** – lenyíló lista; választásra rögtön szűr.
- **Szűrő törlése** – csak keresés vagy kategóriaszűrés után látszik.

### A termék űrlapjának mezői

- **Megnevezés** – kötelező.
- **Kategória**, **Beszállító** – választóból, vagy saját szöveggel.
- **Készletkezelés** – ha kiveszed a pipát, a termék nem fogy a készletből (például apró, nem számolt műhelyanyag).
- **Nyitókészlet** / **Készlet**, **M.e.**, **Min. készlet**.
- **Beszerzési ár**, **Eladási ár**, **Haszonkulcs**, **Kedvezmény**.
- **Kódok:** Gyári szám, Cikkszám, Termékkód, Vonalkód, Osztály, Könyvelve (dátum).
- Alsó lapfülek: **Leírás**, **Megjegyzés** (belső), és meglévő terméknél **Készletmozgás**.
- **Aktív** – meglévő terméknél; ha kiveszed a pipát, a termék inaktív lesz.

Az ablak tetején látod a termék sorszámát, és hogy hány munkalap-tételben szerepel.

## Szabályok, amiket a rendszer betart

- **A készlet a mozgások összege.** Minden változás egy sor a mozgások között, a termék készlete ezekből adódik. Kézzel átírt készlet is mozgásként rögzül.
- **A munkalapra tett termék magától levonódik** – de csak akkor, ha a lap számozott állapotban van, és nem érvénytelen. Az árajánlat és a sablon nem fogyaszt készletet.
- **Az érvénytelenített lap visszaadja a készletet.** Állapotváltáskor a készlet követi a lapot: ha az árajánlatból munkalap lesz, a termék levonódik.
- A munkalap akárhányszor újramenthető: egy tételhez egy mozgás tartozik. Ha átírod a mennyiséget, a mozgás igazodik; ha törlöd a tételt, a termék visszakerül.
- **Csak a választóból felvett termék fogyaszt készletet.** A kézzel beírt sor nem. Ha egy sorban kitörlöd a megnevezést, a sor elengedi a terméket.
- Csak az a termék fogy, amelynél be van kapcsolva a **Készletkezelés**.
- A rendszer nem tiltja meg, hogy készleten nem lévő terméket tegyél a lapra. A készlet ilyenkor mínuszba megy, és a termék a **Rendelni kell** nézetbe kerül.
- A készletet ahhoz az értékhez méri, amit az űrlap megnyitáskor mutatott. Ha közben egy kolléga munkalapja fogyasztott a termékből, azt a mentésed nem írja vissza.
- **Törlés:** ha a termék már szerepelt munkalapon, nem törlődik, csak inaktív lesz. Ha még sosem szerepelt, a mozgásaival együtt törlődik.
- Az inaktív termék a munkalap választójában nem jelenik meg.
- Az eladási ár bruttóban, a beszerzési ár nettóban tárolódik. A haszonkulcs nem tárolt adat, mindig az árakból számolódik.
- A megnevezés nélküli termék nem menthető.

## Tippek

- A jobb alsó sarokban élő asszisztensnek írd be: „Vezess végig ezen az oldalon". Többlépcsős túrát indít a Termékek oldalon.
- A teljes dokumentációt és a gyakori kérdéseket a **Súgó** menüpontban találod.
- Reggel nézd meg a **Rendelni kell** fület: egy pillantással látod, miből kell utánrendelni.
- Add meg a vonalkódot a terméknél. Utána a munkalapon és az árukiadáson elég beolvasni.
- Árcímke vagy polccímke kell? A termék ablakának alján a **Címke** gomb Brother címkenyomtatóra nyomtat (név, ár, cikkszám, vonalkód) – lásd a **Címkenyomtatás (Brother)** fejezetet.
- A **Min. készlet** értékét a gyorsan fogyó alkatrészeknél állítsd be. Enélkül a termék csak akkor kerül a **Rendelni kell** nézetbe, amikor teljesen elfogyott.
- **A változás oka** mezőt mindig töltsd ki. Fél év múlva ebből derül ki, miért lett kevesebb a készlet.
- A lista alján az összesítő sor megmutatja, mennyi pénz áll a készletben. Kategóriára szűrve kategóriánként is látod.
- Ha csak kipróbálnád a modult, az üres listán a **20 mintatermék betöltése** gomb kitalált termékeket tölt be nyitókészlettel.

## Ha valami nem megy

- **„A megnevezés kitöltése kötelező."** – írd be a termék nevét.
- **Nem fogyott a készlet a munkalap mentése után.** Négy oka lehet: a lap árajánlat vagy sablon; a terméket kézzel írtad be, nem a választóból; a terméknél ki van kapcsolva a **Készletkezelés**; vagy a lap érvénytelen.
- **A termék nincs a választóban.** Valószínűleg inaktív. Nézd meg a Termékek **Inaktív** fülén, nyisd meg, pipáld be az **Aktív** jelölőt, és mentsd.
- **Nem jelenik meg „A változás oka" mező.** Csak akkor látszik, ha a **Készlet** mező értéke eltér az eredetitől, és a készletkezelés be van kapcsolva.
- **A Törlés után a termék megmaradt.** Ezt az üzenetet kaptad: „A termék már szerepelt munkalapon, ezért nem törlődött: inaktív lett." Ez így helyes: a régi lapok hivatkoznak rá.
- **Mínuszban van a készlet.** Többet adtál ki, mint amennyi nyilván volt tartva. Számold meg a polcon, és írd be a valós értéket a **Készlet** mezőbe, az okhoz: leltár.
- **Nem számolódik a haszonkulcs.** Nincs beírva beszerzési ár vagy eladási ár.
- **„A termékek listája nem töltődött be."** – a választó nem érte el a szervert. Zárd be, és nyisd meg újra.

## GYIK

### Honnan tudom, mit kell rendelni?

A **Termékek › Rendelési javaslat** nézetből: beszállítónként mutatja, mi van a minimum alatt, és mennyit érdemes rendelni. Megérkezéskor a **Megérkezett** gomb bevételezi.

### Mit jelent, hogy egy alkatrész foglalt?

Nyitott munkalapon van: a javításhoz félre van téve, és már lejött a készletről. A termék űrlapján látszik, melyik lapokon.

### Hogyan veszek fel új terméket?

A Termékek menüpontban kattints az **Új termék** gombra, írd be a megnevezést, a nyitókészletet és az árakat, majd **Termék létrehozása**. A munkalap termékválasztójából is felveheted.

### Hogyan rögzítem, ha megjött az áru?

Nyisd meg a terméket, írd át a **Készlet** mezőt az új összes mennyiségre, **A változás oka** mezőbe írd be, hogy beérkezett rendelés, és mentsd. A rendszer a különbséget Bevételezésként rögzíti.

### A beérkezett darabszámot vagy az új összes készletet kell beírnom?

Az új összes készletet. Ha 3 volt, és 5 érkezett, 8-at írsz. A különbséget a rendszer számolja ki.

### Hogyan javítom a készletet leltár után?

Ugyanúgy: írd be a **Készlet** mezőbe a megszámolt mennyiséget, az okhoz azt, hogy leltár. Ha kevesebb lett, Korrekció, ha több, Bevételezés rögzül.

### Mikor vonódik le a termék a készletből?

A munkalap vagy az árukiadás mentésekor, ha a lap számozott állapotban van, és nem érvénytelen. Árajánlatnál és sablonnál nem vonódik le.

### Árajánlatot írok. Lefoglalja a terméket?

Nem. Az árajánlat nem fogyaszt készletet. Amikor az árajánlatból munkalap lesz, a termék akkor vonódik le.

### Mi történik a készlettel, ha érvénytelenítem a munkalapot?

A rátett termékek visszakerülnek a készletre. A mozgás eltűnik a naplóból, a készlet újraszámolódik.

### Elrontottam a mennyiséget a munkalapon. Kétszer vonja le?

Nem. Írd át a mennyiséget, és mentsd újra a lapot. Egy tételhez egy mozgás tartozik, az igazodik az új mennyiséghez.

### Fel tudok tenni a munkalapra olyan terméket, ami nincs készleten?

Igen, a rendszer nem tiltja. A készlet mínuszba megy, és a termék megjelenik a **Rendelni kell** nézetben.

### Mit jelent a Rendelni kell fül?

Azokat a készletkezelt termékeket mutatja, amelyek elfogytak, vagy a **Min. készlet** értéke alá mentek. A fülön szám jelzi, hány ilyen van.

### Mire jó a Készletkezelés pipa?

Ezzel döntöd el, vezet-e a rendszer készletet a termékről. Ha kiveszed, a termék felvehető a munkalapra, de nem fogy, és a listában a készlet helyén gondolatjel áll.

### Bruttó vagy nettó árat írjak be?

Amelyiket tudod. Mindkét sorban van nettó és bruttó mező, és a másik az áfakulcsból kiszámolódik.

### Hogyan számolja a rendszer a haszonkulcsot?

Az eladási nettó árból kivonja a beszerzési nettót, és elosztja a beszerzési nettóval. Ha például a beszerzési nettó 10 000 Ft, az eladási nettó 15 000 Ft, a haszonkulcs 50%.

### Be tudom állítani, hogy mindig ugyanakkora haszonkulccsal áraz?

Egy terméknél igen: írd be a beszerzési árat, majd a **Haszonkulcs** mezőbe a kívánt százalékot. Az eladási ár kiszámolódik. Ha később változik a beszerzési ár, a haszonkulcsot újra be kell írnod.

### Miért nem tudom törölni a terméket?

Mert már szerepelt munkalapon, és a régi lap hivatkozik rá. Ilyenkor a törlés helyett inaktív lesz: a választóban nem jelenik meg, de a múltja megmarad.

### Hogyan hozok vissza egy inaktív terméket?

A Termékek oldalon kattints az **Inaktív** fülre, nyisd meg a terméket, pipáld be az **Aktív** jelölőt, és mentsd.

### Miért kérdez rá kétszer a Törlés gomb?

Hogy véletlenül ne törölj. Az első kattintásra a felirata „Biztosan törlöd?" lesz, a másodikra hajtja végre. Ha nem kattintasz, néhány másodperc múlva visszaáll.

### Hol látom, melyik munkalapra ment el egy alkatrész?

Nyisd meg a terméket, és nézd meg a **Készletmozgás** fület, vagy az **Összes tételmozgás** nézetet. A „Munkalap" fajtájú sor megjegyzésében ott a munkalap száma, a szóra kattintva megnyílik a lap.

### Ki módosította a készletet?

A készletmozgások között minden sor végén ott a név, aki rögzítette, az időponttal együtt.

### Használhatok vonalkódolvasót?

Igen. Add meg a terméknél a **Vonalkód** mezőt. A munkalap termékválasztójában olvasd be a kódot, és nyomj [[Enter]] billentyűt: a termék a lapra kerül.

### Mennyit ér a teljes készletem?

A Termékek lista alján az összesítő sor mutatja: a készlet értékét beszerzési nettó áron és eladási bruttó áron. Az összeg mindig az éppen szűrt találatra vonatkozik.

### Kézzel is beírhatok terméket a munkalapra?

Igen, a választóban a **Kézzel írom be** gombbal. Az ilyen sor nem kötődik a terméktörzshöz, ezért a készletet nem változtatja.

## Túra: termekek

- `.sdh-fejlec__gombok [data-sdh-urlap="termekek"]` | Új termék | Új alkatrész vagy áru felvétele felugró ablakban. Csak a megnevezés kötelező.
- `.sdh-nezetfulek` | Nézetfülek | Összes termék, Készleten, Rendelni kell, Inaktív és Összes tételmozgás. A számok megmutatják, hány termék tartozik a nézetbe.
- `.sdh-kereso input[name="k"]` | Keresés | Megnevezésre és minden kódra keres: cikkszám, termékkód, gyári szám, vonalkód.
- `.sdh-kereso select[name="kat"]` | Kategóriaszűrő | Válassz kategóriát, és a lista rögtön arra szűkül.
- `.sdh-tabla--termek thead` | A lista oszlopai | Kódok, készlet, beszerzési és eladási ár. Az oszlopfejre kattintva rendezel, újabb kattintás megfordítja.
- `.sdh-tabla--termek tbody .sdh-tabla__nev a` | A termék | A megnevezésre kattintva megnyílik az űrlap: készlet, árak, haszonkulcs, kódok és készletmozgás.
- `.sdh-tabla--termek .sdh-keszlet` | Készletjelzés | A szín megmutatja, hogy van-e készleten, a minimum alá ment-e, vagy elfogyott.
- `.sdh-tabla--termek tfoot` | Összesítő sor | A teljes találat készlete, és a készlet értéke beszerzési nettó és eladási bruttó áron.
