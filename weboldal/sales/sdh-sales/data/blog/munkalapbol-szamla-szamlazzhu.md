---
cim: Munkalapból számla Számlázz.hu-val: dupla adatbevitel nélkül
leiras: Hogyan kerüljön a munkalap tétele a számlára újragépelés nélkül, és hogyan kerüld el a véletlenül kiállított számlát? Gyakorlati útmutató szervizeknek a Számlázz.hu-kapcsolatról.
kategoria: Raktár és pénzügy
cimkek: [számlázz.hu munkalap, munkalapból számla, számlázás, házipénztár]
datum: 2026-08-03
kulcsszo: számlázz.hu munkalap
seo_cim: Munkalapból számla Számlázz.hu-val, dupla adatbevitel nélkül
---

**Röviden:**

- A dupla adatbevitel (munkalapból kézzel a számlázóba) időt visz el, és hibát szül. Ha a szerviz program közvetlenül a számlázóval beszél, a tételek egyszer kerülnek be.
- A számla legyen **mindig szándékos**: csak egy kifejezett gombnyomásra készüljön, előnézet után, soha ne mentéskor vagy lezáráskor.
- A számlaszám kerüljön vissza a munkalapra és a kasszába, hogy az egyeztetés egyszerű legyen.
- A kiállított számlát ne a szerviz programban javítsd, hanem a számlázóban.

## A dupla adatbevitel ára

Egy átlagos szervizes számlázás így néz ki papíron vagy két különálló programmal:

1. A munkalapon ott vannak a tételek: munkadíj, alkatrész, bevizsgálási díj.
2. A pultos megnyitja a számlázót, és újra beírja a vevőt.
3. Egyenként beírja a tételeket, az árakat, az áfát.
4. Kiállítja a számlát, kinyomtatja.
5. A számlaszámot visszaírja a munkalapra, vagy nem.
6. Este a kasszakönyvben összeveti a befizetéseket a számlákkal.

Minden lépés egy lehetőség a hibára: elírt adószám, kimaradt tétel, rossz áfakulcs. És minden lépés idő, ami a pultnál hiányzik.

## Hogyan működik a közvetlen kapcsolat?

A Számlázz.hu rendelkezik egy programozói felülettel (Számla Agent), amelyen keresztül más programok számlát állíthatnak ki a te fiókodban. A szerviz program ezen keresztül küldi el a vevőt és a tételeket, a Számlázz.hu pedig kiállítja a számlát, és visszaküldi a számlaszámot és a PDF-et.

Ehhez kell:

- egy saját Számlázz.hu-előfizetés,
- egy Számla Agent-kulcs, amelyet a Számlázz.hu fiókodban hozol létre,
- a kulcs megadása a szerviz programban (titkosítva tárolva).

A {termeknev} [számlázási funkciója](/funkciok/szamlazas/) így kapcsolódik a Számlázz.hu-hoz.

## A biztonságos számlázás szabályai

### 1. Számla csak gombnyomásra

A legfontosabb: a számla ne készüljön magától. Ha a munkalap mentése, lezárása vagy a fizetés rögzítése számlát állítana ki, előbb-utóbb lesz egy véletlen számla, amelyet sztornózni kell. A jó megoldás, ha a munkalapon lévő gomb csak a **számla ablakát** nyitja meg, ahol látod a vevőt és a tételeket, és a számla csak egy második, kifejezett gombra készül el.

### 2. Előnézet

Mielőtt kiállítod, nézd meg, mi kerül rá. Egy előnézet PDF megmutatja a vevőt, a tételeket és a végösszeget, és ha valami nem stimmel, még javítható.

### 3. Vevő ellenőrzése

Céges vevőnél az adószám alapján a cég neve és címe lekérhető a nyilvántartásból, így nem kell kézzel gépelni. Magánszemélynél elég a név és a cím.

### 4. Tételek kiválasztása

Nem mindig kerül minden tétel a számlára. Például a bevizsgálási díj, ha az ügyfél végül kéri a javítást, beszámíthat a javítás árába. Jó, ha a számla ablakában tételenként kapcsolható, mi kerüljön rá, és tétel is felvehető, amely nincs a munkalapon.

### 5. Kiállított számla nem módosítható

A kiállított számla végleges. Ha hibás, a javítás (sztornó, helyesbítő számla) a Számlázz.hu-ban történik. A szerviz programnak nem szabad megengednie, hogy a számlát utólag módosítsd vagy töröld, mert akkor a két rendszer eltérne.

## Számla-előkészítő: amikor még nem kell számla

Előfordul, hogy az ügyfél egy összesítő papírt kér a fizetés előtt, vagy a számlát később, egyben állítod ki. Ilyenkor jól jön egy helyben készülő PDF, amely felsorolja a tételeket, de egyértelműen jelzi: „Ez a bizonylat nem minősül számlának.” Ez internet és számlázó nélkül is elkészül.

## Számla és kassza egyeztetése

Ha a számlaszám a kiállítás után magától visszakerül a munkalapra és a hozzá tartozó pénztártételekre, az esti egyeztetés sokkal egyszerűbb: látszik, melyik befizetéshez van számla, és melyikhez nincs. A {termeknev} házipénztárában egy egyeztető ügynök is segít: jelzi, ha egy fizetett munkalap nincs a pénztárban, vagy ha egy számlaszám pótolható.

## Internet nélkül

A Számlázz.hu online szolgáltatás, ezért internetkimaradáskor valódi számla nem állítható ki. Jó, ha a szerviz program ilyenkor nem vár percekig, hanem azonnal jelzi, hogy most nem megy, és a kapcsolat visszatérése után a lapról újra kiállítható. Erről az [offline működésnél](/funkciok/offline/) írunk.

## Bevizsgálási díj és előleg a számlán

Két gyakori szervizes helyzet okoz fejtörést a számlázásnál.

**Bevizsgálási díj.** Ha az ügyfél nem kéri a javítást, a bevizsgálási díj önálló tétel a számlán. Ha kéri, sok szerviz beszámítja a javítás árába. Ilyenkor a számlán érdemes egyértelműen jelezni, mennyi volt a díj, és hogyan számítottad be, hogy az ügyfél értse a végösszeget. Jó, ha a szerviz program ezt egységesen kezeli, és nem a pultosnak kell minden alkalommal kitalálnia.

**Előleg.** Ha az ügyfél alkatrészrendeléskor előleget fizet, az előleg a pénztárba kerül, a munkalapon látszik, és a végső fizetendő összeg ennyivel kevesebb. Az előleg számlázásának módjáról (előlegszámla vagy sem) a könyvelőddel egyeztess, mert ez a cég könyvelési gyakorlatától is függ.

## Papír vagy e-számla?

A Számlázz.hu-ban kiállítható papír alapú (nyomtatandó) és elektronikus számla is. Az e-számla PDF-ként megy a vevőnek, nem kell nyomtatni, és a vevő e-mailben megkapja. Ha a vevők többsége magánszemély, aki úgyis a telefonján nézi meg, az e-számla egyszerűbb. A beállítást egyszer kell megadni, és a számla ablakában szükség esetén eltérhetsz tőle.

## Ellenőrzőlista a bevezetéshez

| Teendő | Kész? |
|---|---|
| Számlázz.hu-előfizetés és Agent-kulcs megvan | |
| Kulcs megadva, kapcsolat kipróbálva | |
| Számlatömb, előtag beállítva | |
| E-számla vagy papír számla eldöntve | |
| Próbaszámla teszt üzemmódban | |
| A csapat tudja: számla csak a második gombra készül | |
| A bevizsgálási díj kezelése egységes | |
| Esti egyeztetés: pénztár és számlák | |

## Gyakori kérdések

### Elküldi a rendszer a számlát e-mailben?

Ha a számla ablakában kéred, a Számlázz.hu küldi el a vevő e-mail-címére.

### Több eszköz van a munkalapon. Lehet külön számla?

Igen, eszközönként külön számla is kiállítható, ha a vevő így kéri.

### Hol találom meg később a számla PDF-jét?

A munkalapon, a számlaszámra kattintva.

## Összegzés

A munkalapból számlázás akkor jó, ha egyszerre gyors és biztonságos: a tételek egyszer kerülnek be, a számla csak szándékosan készül el, és a számlaszám visszakerül a munkalapra és a kasszába. Így a pultnál percek szabadulnak fel, este pedig egyezik a kassza.

A {termeknev} [demójában](/demo/) megnézheted a számla ablakát és előnézetét (a demóban valódi számla nem készül). A modulokat és árakat az [Árak](/arak/) oldalon találod.
