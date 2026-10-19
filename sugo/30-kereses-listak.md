---
kulcs: kereses-listak
cim: Keresés, listák és űrlapok e-mailben
csoport: Rendszer
modul:
szakaszok: kereses, urlap_email
kulcsszavak: keresés, kereső, okos kereső, azonnali találat, lenyíló, javaslat, imei keresés, gyári szám keresés, telefonszám keresés, márka keresés, sorszám keresés, keresés mindenben, pontos találat, lapozó, oldalszám, ugrás oldalra, oldalugró, dupla kattintás, sor megnyitása, szűrő, szűrő kereső, áttekintés szűrő, állapot pont, színes pont, állapot oszlop, e-mail gomb, továbbítás e-mailben, űrlap e-mailben, pdf e-mailben, fájlok lapfül, dokumentum feltöltés, dokumentumok elküldése, dokumentum e-mail cím, lassú lista, internal server error
osszefoglalo: Minden kereső gépelés közben találatot ad és felismeri, mit írtál be (IMEI, telefonszám, sorszám, kód, márka, név); a lapozóknál oldalszámra lehet ugrani, a sorok dupla kattintásra nyílnak, az Áttekintés szűrőiben lehet keresni, az Állapot oszlop pontra csukható; minden űrlap e-mailben továbbítható, a munkalapnak Fájlok lapfüle van.
---
## Mire való

A rendszer minden keresője ugyanúgy működik, mint a fejléc keresője: **gépelés közben lenyílik a találatok listája**, és egyszerre keres ügyfelet, eszközt, munkalapot (árukiadást, rendelést), terméket és szolgáltatást. Közben felismeri, **mit írtál be**, és az ahhoz illő találatot teszi előre:

| Amit beírsz | Mit ismer fel | Mi kerül előre |
|---|---|---|
| 14–17 számjegy (pl. 357146129282436) | **IMEI** | az eszköz, aztán a lapjai |
| telefonszám (06 30…, +36 30…, 7–13 jegy) | **telefonszám** | a **teljes ügyfél**, aztán a lapjai |
| rövid szám (pl. 1234) | **sorszám** | a munkalap / árukiadás / rendelés ezzel a számmal |
| betű és szám vegyesen, szóköz nélkül (pl. R58N12ABC) | **kód** (gyári szám, modellszám, cikkszám, vonalkód) | az eszköz, a termék |
| ismert gyártó az első szóban (pl. Samsung A52) | **márka** | a márka készülékei és termékei |
| e-mail cím | **e-mail** | az ügyfél |
| minden más | **név / szöveg** | az ügyfél, a termék, a szolgáltatás |

A lenyíló tetején kiírja, minek ismerte fel a szöveget („Felismerve: IMEI-szám”). A találatra kattintva a rekord a **saját ablakában** nyílik (az oldal nem vált). A nyilakkal (↑ ↓) is lépkedhetsz, Enterrel nyitod meg. Ha nem választasz, az **Enter** a lista keresését indítja, mint eddig.

## A listák keresése: első körben pontosan

A listák (Ügyfelek, Eszközök, Munkalapok, Napi árukiadás, Rendelések, Termékek, Szolgáltatások) keresője is felismeri a beírtat, és **először csak abban keres**: IMEI-re az IMEI-ben, telefonszámra az ügyfél telefonjában, sorszámra a sorszámban, kódra a kódokban, több szóra úgy, hogy minden szó szerepeljen a névben. A keresőmező alatt kiírja, mi szerint keresett („Pontos találat: IMEI szerint”), és ott a **Keresés mindenben** link, ha mégis mindenben akarsz keresni.

Ha a pontos keresés nem talál semmit, a rendszer **magától mindenben keres**, és ezt is kiírja. (IMEI-nél nem: ott a pontos keresés a teljes – ha kell, a **Keresés mindenben** linkkel mégis.)

## Lapozás, oldalszámra ugrás

A számozott lapozó (1 2 … 11801) végén egy kis mező áll: írd be az oldalszámot, és nyomj **Entert** (vagy az **Ugrás** gombot). Az Áttekintés rácsának eszköztárában is ott a mező az oldalszám mellett. Ha nagyobb számot írsz, mint ahány oldal van, az utolsó oldalra ugrik.

A nagyon hátsó oldalak is gyorsan jönnek: a lista előbb csak az oldal azonosítóit olvassa a keskeny indexről, és csak a látszó 25 sort tölti be teljesen.

## Dupla kattintás

A Munkalapok, Eszközök, Napi árukiadás, Rendelések, Termékek, Ügyfelek és Szolgáltatások listájában a sorra **duplán kattintva** megnyílik a rekord – ugyanúgy, mint a **Megnyit** gombbal. Mezőre, gombra, linkre és az állapotválasztóra a dupla kattintás nem nyit.

## Az Áttekintés szűrői

- A **választós szűrők** (Állapot, Felelős, Jelzés, Azonosító, Garancia, Fizetve) ablakának tetején kereső áll: gépelésre a lista szűkül. **Enter** az első látható értéket jelöli ki; a **Mind** gomb kereséskor csak a láthatókat jelöli.
- A **szöveges szűrők** (Ügyfél, Telefonszám, Gyártó, Típus, IMEI, Sorozatszám, Cím, Név, Megjegyzés…) gépelés közben **javaslatot** adnak a rekordokban előforduló értékekből; a javaslatra kattintva (vagy ↑ ↓ + Enter) az lesz a szűrő.

## Az Állapot oszlop pontként

Az Áttekintés **Oszlopok** gombja alatt: **Állapot csak színes pont**. Bekapcsolva az Állapot oszlop keskeny, csak a színes pont látszik; ráállva kiírja az állapot nevét, **rákattintva ugyanúgy lenyílik az állapotválasztó**. A beállítás a te gépeden marad meg; az új felhasználók alapját a Beállítások › Keresés és listák lapfül adja.

## Űrlap továbbítása e-mailben

Minden fő űrlap (Munkalap, Napi árukiadás, Rendelés, Ügyfél, Eszköz, Termék, Szolgáltatás) alsó gombsorának **utolsó gombja** az **E-mail**. Rákattintva kis ablak nyílik:

1. **Címzett** – az ügyfél e-mail címe, ha van (több címet vesszővel írhatsz).
2. **Tárgy** és **Üzenet** – az űrlap adataival kitöltve, átírható.
3. A munkalapnál, árukiadásnál, rendelésnél a **nyomtatvány PDF-je** is mehet csatolmányként (kapcsoló).
4. **Küldés** – a levél a cég levelezőfiókjából megy (Beállítások › Űrlapok e-mailben), és a lap naplójába bekerül, kinek ment.

A feloldó kód és a minta soha nem kerül a levélbe. Az E-mail gomb csak mentett rekordnál látszik.

## A munkalap Fájlok lapfüle

A munkalap lapfüleinek utolsó eleme a **Fájlok**: ide bármilyen képet és dokumentumot feltölthetsz (fotó, PDF, számla, nyilatkozat, Word, Excel) – ugyanúgy, mint az eszköz felvételénél: húzd a mezőbe, vagy **+ Fájl hozzáadása**. A fájlok a lap **mentésekor** kerülnek fel; a × gombbal törlésre jelölöd (mentéskor törlődik).

Ha a Beállításokban meg van adva **a dokumentumok e-mail címe**, a lapfülön ott a **Dokumentumok elküldése** gomb: a lap összes feltöltött dokumentumát (a Fájlok és az Átvétel fotóit is) egy levélben elküldi arra a címre. Egy levél legfeljebb 20 MB lehet; ami nem fér bele, azt a levél felsorolja.

## Beállítások

- **Beállítások › Cég és megjelenés › Keresés és listák:** azonnali találatok minden keresőben; típus szerinti (okos) keresés; oldalszám-ugró; dupla kattintás; szűrő-kereső az Áttekintésben; az Állapot oszlop alapja új felhasználónak. Mindegyik külön kapcsolható.
- **Beállítások › Munkalap › Űrlapok e-mailben:** az E-mail gomb űrlaponként; a PDF csatolása; a Fájlok lapfül; a dokumentumok e-mail címe; melyik fiókból menjen a levél.

## Ha valami nem megy

- **Nem nyílik le a találatlista a lista keresőjénél:** be van-e kapcsolva az „Azonnali találatok minden keresőben” (Beállítások › Keresés és listák). Legalább két betű kell.
- **A keresés kevesebbet talál, mint vártam:** a lista először pontosan keresett (lásd a kereső alatti sort) – kattints a **Keresés mindenben** linkre.
- **Nincs E-mail gomb:** az űrlap még nincs mentve, vagy annál az űrlapnál ki van kapcsolva.
- **A levél nem ment el:** Tesztüzemben levél nem megy ki. Élesben nézd meg a Levelezés fiókját, vagy válaszd a szerver levélküldőjét.
- **Nincs Dokumentumok elküldése gomb:** nincs megadva a dokumentumok e-mail címe, vagy a lap még nincs mentve.

## GYIK

### Hogyan keresek IMEI-re?

Írd be az IMEI-t bármelyik keresőbe (a fejlécben vagy egy listában). A rendszer felismeri, és az eszközt, majd a lapjait teszi előre.

### Hogyan találom meg az ügyfelet telefonszámból?

Írd be a telefonszámot (06-tal, +36-tal vagy anélkül). A teljes ügyfél kerül előre, alatta a lapjai.

### Hogyan ugrom egy távoli oldalra?

A lapozó végén álló mezőbe írd be az oldalszámot, és nyomj Entert.

### Meg lehet nyitni a sort dupla kattintással?

Igen, a listák sorai dupla kattintásra megnyílnak. Kikapcsolható: Beállítások › Keresés és listák.

### Hogyan küldöm el a munkalapot e-mailben?

A munkalap alsó gombsorának utolsó gombja az **E-mail**: címzett, tárgy, üzenet, és a PDF is mehet.

### Hova töltsem fel a munkalaphoz tartozó dokumentumokat?

A munkalap **Fájlok** lapfülére (a lapfülek sorának utolsó eleme).

### Hogyan küldöm el egyszerre a lap összes dokumentumát?

A Fájlok lapfülön a **Dokumentumok elküldése** gombbal – a címet a Beállítások › Űrlapok e-mailben lapfülön adod meg.

### Miért csak színes pont látszik az Állapot oszlopban?

Be van kapcsolva az **Állapot csak színes pont** (Oszlopok gomb). Ráállva látszik az állapot neve, rákattintva választhatsz.

### Lehet keresni az Áttekintés szűrőiben?

Igen: a választós szűrők ablakában kereső áll, a szöveges szűrők javaslatot adnak.

### Miért lassú vagy ad hibát egy nagyon hátsó oldal?

Már nem kellene: a lista előbb csak az oldal azonosítóit olvassa egy keskeny indexről, és csak a látszó sorokat tölti be. Ha mégis lassú, a Beállítások › Sebesség › **Mérés indítása** megmutatja, melyik lépés lassú (HDD-n a hideg gyorsítótár is lassít).
