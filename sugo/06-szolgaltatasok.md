---
kulcs: szolgaltatasok
cim: Szolgáltatások
csoport: Napi munka
modul: szolgaltatasok
szakaszok:
kulcsszavak: szolgáltatás, munkadíj, díj, ár, árlista, díjlista, bevizsgálási díj, bevizsgálás, sávos ár, áfa, áfakulcs, nettó, bruttó, kategória, kód, szolgáltatástörzs, tétel, szolgáltatás-választó, összevonás
osszefoglalo: A Szolgáltatások menüpont a munkadíjak és a bevizsgálási díjak törzse; innen választasz szolgáltatást a munkalapra, és itt tartod karban az árlistát.
---

## Mire való

A **Szolgáltatások** menüpont a munkadíjak törzse. Itt van minden, amit a munkalapra szolgáltatásként felírsz: kijelzőcsere munkadíja, szoftverfrissítés, adatmentés, kiszállás – és a bevizsgálási díjak is.

A törzs két úton telik meg:

- **Kézzel:** felveszel egy szolgáltatást, vagy egyben beilleszted a saját árlistádat.
- **Magától:** amit a munkalap Szolgáltatások lapfülén egyszer beírtál, azt a rendszer mentéskor megjegyzi a nevével, az egységével, az árával és az áfakulcsával.

Legközelebb elég kiválasztani: a munkalap sorába bekerül a név, az egység és a bruttó ár. A rendszer nem hoz magával egyetlen árat sem – az árlista a te adatod.

## Hol találod

- Az oldalmenüben a **Szolgáltatások** pontra kattintva: [Szolgáltatások megnyitása](modul:szolgaltatasok).
- Az Áttekintésen az **Új szolgáltatás** gombbal.
- A munkalap **Szolgáltatások** lapfülén a szolgáltatás-választó ablakból: ott is felvehetsz és szerkeszthetsz.

A Szolgáltatások oldal tetején három nézet közül választasz: **Mind**, **Bevizsgálási díjak**, **Árlista**.

## Lépésről lépésre: új szolgáltatás felvétele

1. A Szolgáltatások menüpontban kattints az **Új szolgáltatás** gombra.
2. Írd be a megnevezést a felső mezőbe. **Csak a megnevezés kötelező.**
3. Írd be a **Bruttó ár** mezőbe az egységárat. A **Nettó ár** mellette magától kiszámolódik.
4. Válaszd ki az **Áfakulcs** értékét.
5. A **Menny. egység** alapból `db`. Írd át, ha kell (például óra, km).
6. Ha szeretnéd csoportosítani, adj meg **Kategória** nevet és **Kód** értéket. A kategória mező felajánlja a már használt kategóriákat.
7. Sávos árnál írd be az **Ár felső határa** mezőt is. Üresen hagyva az ár fix.
8. Ha ez egy bevizsgálási díj, pipáld be a **Bevizsgálási díj** jelölőt.
9. Az alsó mezőbe belső megjegyzést írhatsz: mit tartalmaz, mikor adható.
10. Kattints a **Szolgáltatás létrehozása** gombra.

> **Példa:** Megnevezés: Kijelzőcsere munkadíj. Bruttó ár: 12 000 Ft. Áfakulcs: 27%. Menny. egység: db.

## Lépésről lépésre: szolgáltatás szerkesztése és törlése

1. A Szolgáltatások listában kattints a szolgáltatás nevére vagy a **Megnyit** gombra.
2. Az ablak tetején látod, eddig hány munkalap-tételben szerepelt.
3. Írd át, amit kell, és kattints a **Mentés** gombra.

Törlés:

1. Nyisd meg a szolgáltatást.
2. Kattints a **Törlés** gombra. A gomb felirata „Biztosan törlöd?" lesz.
3. Kattints rá még egyszer néhány másodpercen belül. Ha nem teszed, a gomb visszaáll, és nem történik semmi.

A törlés csak a törzsből veszi ki a szolgáltatást. A munkalapokon már szereplő tételek megmaradnak, a régi lapok nem változnak.

## Lépésről lépésre: árlista beillesztése

Ha van kész díjlistád weboldalon, szövegszerkesztőben vagy táblázatban, nem kell egyenként felvinned.

1. A Szolgáltatások menüpontban kattints az **Árlista beillesztése** gombra.
2. Másold ki a saját árlistádat, és illeszd be a bal oldali szövegdobozba. Soronként egy tétel: megnevezés, utána az ár.
3. A jobb oldalon rögtön megjelenik az **előnézet**: hány tételt talált, ebből mennyi a bevizsgálási díj, mennyi az új, és mennyi frissül.
4. Nézd át az előnézet táblázatát. A **Díj** oszlop pipájával soronként átállíthatod, mi számítson bevizsgálási díjnak. Az új tételek mellett „új" jelzés áll.
5. Döntsd el, maradjon-e bepipálva az „Ez a teljes díjlista…" jelölő (lásd lent).
6. Kattints a **Beillesztés a szolgáltatások közé** gombra.

Mentés után az **Árlista** nézetre kerülsz, és ezt látod: „Az árlista betöltve: az új tételek létrejöttek, a meglévők ára frissült."

### Hogyan írd a sorokat

| Sor | Mi lesz belőle |
|---|---|
| `Alap bevizsgálás 5.000 Ft` | Tétel fix árral |
| `Szoftver frissítés 3.000 – 6.000 Ft` | Tétel sávos árral |
| `Telefon: 100-` | Kategória kóddal; az alatta álló tételek bevizsgálási díjak |
| `Egyéb szolgáltatások` | Kategória kód nélkül; az alatta álló tételek sima szolgáltatások |
| `Sürgősségi felár (000-2) 2.000 Ft` | Tétel saját kóddal; a felár nem lesz díj |

Tudnivalók:

- Az ár írható így is: `5.000 Ft`, `5 000 Ft`, `5000 Ft`.
- Ha egy kód nélküli fejlécben szerepel a „bevizsgál" szó, az alatta álló tételek díjak lesznek.
- A bevizsgálási díj neve „Kategória – Tétel" formában kerül a törzsbe, hogy a díjlistában egyértelmű legyen.
- Amelyik sorban nincs érvényes ár, az kimarad. Az előnézet alján felsorolja: „Kihagyott sorok".
- Ha ugyanaz a név ugyanazzal az árral kétszer szerepel, egyszer kerül be. Eltérő árnál a kategória neve kerül a név elé.

### A „teljes díjlista" jelölő

Alapból be van pipálva. Ilyenkor a rendszer úgy veszi, hogy a beillesztett szöveg a teljes díjlistád: amelyik régi tétel eddig díj volt, de az új listában nincs benne, arról lekerül a „Bevizsgálási díj" jelölés. A tétel maga megmarad szolgáltatásként.

Ha csak néhány új tételt illesztesz be, **vedd ki a pipát**, különben a többi díjad elveszíti a jelölését.

## Lépésről lépésre: árak átírása az Árlista nézetben

A Szolgáltatások oldal **Árlista** nézete kategóriánként mutatja a tételeket, és helyben átírhatod az árakat.

1. Kattints az **Árlista** nézetre az oldal tetején.
2. Írd át a kívánt sorban az **Ár** mezőt. Sávos árnál a **–ig** mezőbe kerül a felső határ.
3. A **Díj** oszlop pipájával állítod, hogy a tétel bevizsgálási díj-e.
4. A módosított sorok kiemelve állnak, amíg nem mentesz.
5. Kattints az **Árak mentése** gombra.

A gomb mellett megjelenik az eredmény: „Elmentve", a megváltozott tételek számával, vagy „Nem volt változás."

További gombok ebben a nézetben:

- **Csak a bevizsgálási díjak** – a díjakra szűr. Visszafelé a felirata **Teljes árlista**.
- **Nyomtatás** – kinyomtatja az árlistát.

Az Árlista nézetben azok a tételek látszanak, amelyeknek van kategóriájuk, bevizsgálási díjak, vagy sávos az áruk. A kategória nélküli díjak az „Egyéb" csoportba kerülnek.

## Lépésről lépésre: szolgáltatás választása a munkalapon

A munkalap **Szolgáltatások** lapfülén a szolgáltatást felugró választóból veszed fel.

1. Nyisd meg a munkalapot, és kattints a **Szolgáltatások** lapfülre.
2. Kattints a pluszjeles **Szolgáltatás** gombra. Megnyílik a *Szolgáltatás választása* ablak.
3. Kezdd el írni a megnevezést a keresőbe. A lista gépelés közben szűkül.
4. Kattints a sorra, vagy lépj rá a [[↑]] [[↓]] nyilakkal, és nyomj [[Enter]] billentyűt.
5. A munkalap sorába bekerül a megnevezés, az egység és a bruttó ár. A kurzor a mennyiségre áll.

A választó ablak további lehetőségei:

- **Új szolgáltatás** – felveszed, ami még nincs a törzsben. A keresőbe írt szöveg lesz a megnevezés, és mentés után a tétel rögtön a munkalapra kerül.
- **Ceruza a sor végén** – a szolgáltatás szerkesztése, a választó elhagyása nélkül.
- **Kézzel írom be** – üres sort ad a munkalapon, a nevet és az árat te írod be.
- **Mégsem** – bezárja a választót.

A lista tíz soronként lapoz, görgetősáv nincs. Elöl a bevizsgálási díjak, utánuk a leggyakrabban használt szolgáltatások állnak.

> **Tipp:** A munkalap sorában a Megnevezés mező végén is van egy kis gomb. Azzal – vagy a mezőben a [[↓]] billentyűvel – abba a sorba választasz szolgáltatást.

## A bevizsgálási díj és a szolgáltatástörzs

A bevizsgálási díjak ugyanebben a törzsben élnek. Attól lesz egy szolgáltatás bevizsgálási díj, hogy be van pipálva rajta a **Bevizsgálási díj** jelölő. A Szolgáltatások listában az ilyen tétel neve mellett „díj" jelvény áll, és a **Bevizsgálási díjak** nézet csak ezeket mutatja.

A munkalapon a **Bevizsgálási díj** jelölő mellett a **Díjlista** gomb nyitja meg a díjválasztót. Ebben csak a díjnak jelölt szolgáltatások szerepelnek. Kereshetsz eszközre, díjra vagy kódra.

Amikor kiválasztasz egy díjat:

- az összege bekerül a **Fizetett (előleg)** mezőbe,
- a **Bevizsgálási díj** jelölő bepipálódik,
- a díj külön tételként megjelenik a munkalapon.

A **Ha az ügyfél kéri a javítást** kapcsolóval döntöd el a díj sorsát: **Levonódik** – a díj levonódik a végösszegből; vagy **Munkadíj marad** – a díj megmarad munkadíjként. Erről részletesen a [Munkalapok](sugo:munkalapok) fejezet ír.

A díjak árát a Szolgáltatások **Árlista** nézetében írod át. A munkalap a díjat mindig innen veszi.

## Mezők és gombok

### A Szolgáltatások lista oszlopai

| Oszlop | Mit mutat |
|---|---|
| Megnevezés | A név; mellette „díj" jelvény, alatta a kategória, a kód és a megjegyzés eleje |
| M.e. | Mennyiségi egység |
| Bruttó ár | Fix ár vagy ársáv |
| Nettó ár | A bruttóból számolva |
| Áfa | Az áfakulcs |
| Használat | Hány munkalap-tételben szerepelt |

### Gombok és nézetek a lista fölött

- **Árlista beillesztése** – teljes díjlista felvitele szövegből.
- **Új szolgáltatás** – egy szolgáltatás felvétele.
- **Mind**, **Bevizsgálási díjak**, **Árlista** – a három nézet.
- **Keresés** – megnevezésre, megjegyzésre, kategóriára és kódra keres.
- **Szűrő törlése** – csak keresés után látszik.
- **Leggyakoribbak elöl** – a használat szerint rendez. Visszafelé a felirata **Névsorban**.

### A szolgáltatás űrlapjának mezői

- **Megnevezés** – kötelező, legfeljebb 255 karakter.
- **Bruttó ár** – bruttó egységár forintban.
- **Áfakulcs** – 27%, 18%, 5%, 0%, vagy valamelyik adómentes jogcím.
- **Menny. egység** – alapból db.
- **Nettó ár** – csak kiírás, a bruttóból és az áfakulcsból számolódik.
- **Kategória** és **Kód** – az árlista csoportosításához.
- **Ár felső határa** – sávos ár felső értéke. Csak akkor él, ha nagyobb a bruttó árnál.
- **Bevizsgálási díj** – ettől kerül a tétel a díjlistába.
- **Megjegyzés** – belső megjegyzés.

## Szabályok, amiket a rendszer betart

- **Egy névből csak egy van.** Két szolgáltatás akkor számít egyformának, ha a nevük megegyezik; a kis- és nagybetű, valamint a szóközök száma nem számít.
- Ha új szolgáltatást veszel fel egy már létező névvel, nem jön létre második sor: a meglévő frissül, és a név eredeti írásmódja marad.
- Ha egy szolgáltatást átnevezel egy már létező névre, a kettő összeolvad: egy marad, a használatuk összeadódik. Erről üzenetet kapsz: „Ilyen nevű szolgáltatás már volt – a kettőt összevontam, egy maradt."
- **A munkalap mentése tanítja a törzset.** Az új nevű szolgáltatás bekerül; a meglévőnél nő a használat számlálója, és az ára a legutóbb használt árra frissül.
- Nulla ár nem írja felül a megjegyzett árat.
- **Az árlistás tétel árát a munkalap nem írja át.** Ami bevizsgálási díj, aminek kategóriája van, vagy sávos az ára, annak az árát csak te módosítod a Szolgáltatások menüpontban.
- A bevizsgálási díj munkalapi tételsorát a törzs nem tanulja meg, mert a neve készülékenként más.
- A törlés a munkalapok tételeihez nem nyúl.
- A megnevezés nélküli szolgáltatás nem menthető.
- A sávos ár felső határa csak akkor mentődik, ha nagyobb az alsónál.

## Tippek

- A jobb alsó sarokban élő asszisztensnek írd be: „Vezess végig ezen az oldalon". Többlépcsős túrát indít a Szolgáltatások oldalon.
- A teljes dokumentációt és a gyakori kérdéseket a **Súgó** menüpontban találod.
- Adj beszédes nevet: a „Kijelzőcsere munkadíj" jobb, mint a „Munkadíj". A munkalapon és a számlán is ez a név jelenik meg.
- Az árlistát beillesztés előtt nézd át az előnézetben. Ami ott rossz, azt a szövegdobozban javítsd ki – az előnézet azonnal frissül.
- Áremelésnél nem kell egyenként megnyitni a tételeket: az **Árlista** nézetben egy oldalon átírod mindet, és egyszer mentesz.
- A **Leggyakoribbak elöl** rendezés megmutatja, mely szolgáltatásokból él a műhely.
- Árcímke a pultra: a szolgáltatás ablakának alján a **Címke** gomb Brother címkenyomtatóra nyomtat (név, ár vagy ársáv, kategória) – lásd a **Címkenyomtatás (Brother)** fejezetet.

## Ha valami nem megy

- **„A megnevezés kitöltése kötelező."** – a felső mező üres.
- **„Nem találtam árat tartalmazó sort."** – az árlista soraiban nincs felismerhető ár. Soronként legyen megnevezés, utána az ár, például „Bevizsgálás 9.000 Ft".
- **Egy sor kimaradt a beillesztésből.** Nézd meg az előnézet alján a kihagyott sorokat: ezekben nincs érvényes ár.
- **Egy ezer forint alatti tételből kategória lett.** Az ezer forintnál kisebb összeget a beillesztés csak akkor veszi árnak, ha pénznem áll utána: `Fóliázás 500 Ft` vagy `Fóliázás 500,-`. A puszta `Fóliázás 500` sort kategóriának nézi – írd mögé a `Ft`-ot.
- **Eltűnt a díj jelölés több tételről.** Részleges listát illesztettél be bepipált „teljes díjlista" jelölővel. Az **Árlista** nézet **Díj** oszlopában pipáld vissza őket, és mentsd.
- **A szolgáltatás nincs az Árlista nézetben.** Ott csak a kategóriás, a díjnak jelölt és a sávos árú tételek látszanak. Adj neki kategóriát, vagy keresd a **Mind** nézetben.
- **A munkalapon a díjválasztó üres.** Még nincs bevizsgálási díj a törzsben. Illeszd be a díjlistát, vagy egy szolgáltatásnál pipáld be a **Bevizsgálási díj** jelölőt.
- **„A szolgáltatások listája nem töltődött be."** – a választó nem érte el a szervert. Zárd be, és nyisd meg újra.

## GYIK

### Hogyan veszek fel új szolgáltatást?

A Szolgáltatások menüpontban kattints az **Új szolgáltatás** gombra, írd be a megnevezést és a bruttó árat, majd **Szolgáltatás létrehozása**. A munkalap választó ablakából is felveheted.

### Bruttó vagy nettó árat kell megadnom?

Bruttót. A **Bruttó ár** mezőbe a bruttó egységárat írod, a nettót a rendszer az áfakulcsból számolja ki, és kiírja mellé.

### Fel kell vennem előre minden szolgáltatást?

Nem. Amit a munkalapon kézzel beírsz, azt a rendszer a munkalap mentésekor megjegyzi. Legközelebb már a választóból kiválaszthatod.

### Miért nem tudok két egyforma nevű szolgáltatást felvenni?

Mert a törzsben egy névből mindig egy van. Így a választóban nem kell találgatnod, melyik a jó. Ha eltérő árú változat kell, adj neki eltérő nevet.

### Mi történik, ha egy szolgáltatást egy meglévő nevére nevezek át?

A kettő összeolvad: egy marad, a használatuk összeadódik. A rendszer ezt üzenetben jelzi.

### Számít a kis- és nagybetű a névben?

Nem. A „Kijelzőcsere" és a „kijelzőcsere" ugyanaz a szolgáltatás, és a többszörös szóköz sem számít. A törzsben az elsőként rögzített írásmód marad.

### Ha törlök egy szolgáltatást, eltűnik a régi munkalapokról?

Nem. A törlés csak a törzsből veszi ki. A munkalapokon már szereplő tételek megmaradnak.

### Miért kérdez rá kétszer a Törlés gomb?

Hogy véletlenül ne törölj. Az első kattintásra a gomb felirata „Biztosan törlöd?" lesz, a másodikra töröl. Ha nem kattintasz, néhány másodperc múlva visszaáll.

### Hogyan viszem fel egyszerre a teljes árlistámat?

Az **Árlista beillesztése** gombbal. Másold be a listát a szövegdobozba, nézd át az előnézetet, és kattints a **Beillesztés a szolgáltatások közé** gombra.

### Milyen formában kell lennie a beillesztett árlistának?

Soronként egy tétel: megnevezés, utána az ár. A kategória külön sorban áll a tételei fölött. A weboldalról, szövegszerkesztőből vagy táblázatból kimásolt szöveg általában átalakítás nélkül jó.

### Ha újra beillesztem az árlistát, megduplázódnak a tételek?

Nem. Név szerint azonosít: ami már megvan, annak az ára, a kategóriája és a kódja frissül, ami nincs, az létrejön. A használat számlálója megmarad.

### Mi az a sávos ár?

Olyan ár, amelynek alsó és felső határa van, például 3 000 – 6 000 Ft. A felső határt az **Ár felső határa** mezőbe írod. A munkalapra az alsó érték kerül, azt a sorban átírhatod.

### Hogyan lesz egy szolgáltatásból bevizsgálási díj?

Nyisd meg, pipáld be a **Bevizsgálási díj** jelölőt, és mentsd. Az **Árlista** nézet **Díj** oszlopában is bepipálhatod. Ettől kezdve megjelenik a munkalap díjválasztójában.

### Hol írom át a bevizsgálási díj összegét?

A Szolgáltatások **Árlista** nézetében, a díj sorában. Az **Árak mentése** után a munkalap díjválasztója már az új árat kínálja.

### A munkalapon más árat írtam be, mint ami a törzsben van. Átíródik a törzs?

Sima szolgáltatásnál igen: a törzs a legutóbb használt árat jegyzi meg. Az árlistás tételnél – díj, kategóriás vagy sávos árú – nem: annak az árát csak a Szolgáltatások menüpontban módosíthatod.

### Mit mutat a Használat oszlop?

Azt, hogy a szolgáltatás hány munkalap-tételben szerepelt eddig. A **Leggyakoribbak elöl** gomb e szerint rendezi a listát.

### Hogyan választok szolgáltatást a munkalapra egér nélkül?

A Megnevezés mezőben nyomd meg a [[↓]] billentyűt. A választóban gépelj, a nyilakkal lépj a sorra, és [[Enter]]. A kurzor a mennyiségre ugrik.

### Nem találom a szolgáltatást a választóban. Mit tegyek?

Írj be kevesebbet vagy más szót: minden beírt szónak szerepelnie kell a névben, de az ékezet nem számít. Ha tényleg nincs meg, az **Új szolgáltatás** gombbal helyben felveheted.

### Ki tudom nyomtatni az árlistát?

Igen. A Szolgáltatások **Árlista** nézetében kattints a **Nyomtatás** gombra. Ha csak a díjak kellenek, előbb kattints a **Csak a bevizsgálási díjak** gombra.

### Mire jó a Kategória és a Kód mező?

Az árlista csoportosítására. Az **Árlista** nézet kategóriánként külön táblázatban mutatja a tételeket, a kód pedig a díjlistában segít eligazodni. Keresni is lehet rájuk.

### Hozhat a rendszer magával kész árakat?

Nem. Az árlista a te adatod: vagy beilleszted, vagy egyenként felveszed, vagy a munkalapok mentése során gyűlik össze.

## Túra: szolgaltatasok

- `.sdh-fejlec__gombok [data-sdh-urlap="szolgaltatasok"]` | Új szolgáltatás | Egy szolgáltatás felvétele felugró ablakban. Csak a megnevezés kötelező, az ár bruttó egységár.
- `.sdh-fejlec__gombok [data-sdh-urlap="szolgaltatasarlista"]` | Árlista beillesztése | Itt másolod be egyben a saját díjlistádat. Az előnézet megmutatja, mit ért belőle a rendszer.
- `.sdh-szolg-pillek` | Nézetek | Mind: a teljes törzs. Bevizsgálási díjak: csak a díjak. Árlista: kategóriánként, helyben írható árakkal.
- `.sdh-kereso input[name="k"]` | Keresés | Megnevezésre, megjegyzésre, kategóriára és kódra keres.
- `.sdh-kereso a[href*="gyakori"]` | Leggyakoribbak elöl | Átrendezi a listát a használat szerint, hogy lásd, mit adsz el a legtöbbször.
- `.sdh-tabla--szolg thead` | A lista oszlopai | Megnevezés, egység, bruttó és nettó ár, áfa, és hogy hány munkalap-tételben szerepelt.
- `.sdh-tabla--szolg tbody .sdh-tabla__nev a` | A szolgáltatás | A névre kattintva megnyílik szerkesztésre. Egy névből mindig csak egy van a törzsben.
- `.sdh-tabla--szolg .sdh-szolg-dij` | Díj jelvény | Ez a tétel bevizsgálási díj: a munkalapon a díjlistából választható.
