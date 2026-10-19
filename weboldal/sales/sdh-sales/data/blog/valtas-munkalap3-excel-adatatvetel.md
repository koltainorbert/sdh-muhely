---
cim: Váltás MunkaLap 3-ról vagy Excelből: adatátvétel CSV/XLSX-ből, lépésről lépésre
leiras: Hogyan hozd át az ügyfeleket, készülékeket és munkalapokat egy régi programból vagy táblázatból úgy, hogy semmi ne vesszen el, és ne legyen semmi kétszer? Gyakorlati útmutató.
kategoria: Váltás
cimkek: [MunkaLap 3 adatátvétel, Excelről váltás, CSV import, XLSX import, adatmigráció]
datum: 2026-09-28
kulcsszo: MunkaLap 3 adatátvétel
seo_cim: Váltás MunkaLap 3-ról vagy Excelből: adatátvétel lépésről lépésre
---

**Röviden:**

- A régi adatok átvétele a váltás legkritikusabb része: ha az ügyfelek és a javítások nem jönnek át, a csapat két rendszerben dolgozik.
- A sorrend számít: **ügyfelek → eszközök → munkalapok → tételek → hibák**, mert mindegyik hivatkozik az előzőre.
- Előbb **próba** (írás nélkül), utána éles átvétel. Hasonlítsd össze a próba összesítőjét a régi program számaival.
- Előtte mentés. A jó rendszerben az átvétel vissza is vonható.

## Miért félnek sokan a váltástól?

A régi program évek óta megy, mindenki ismeri, és benne van a cég teljes története. A váltás azzal fenyeget, hogy valami elveszik: egy ügyfél, egy garancia, egy munkalapszám, amire az ügyfél hivatkozik. Ez a félelem jogos, és a válasz nem az, hogy „majd megoldjuk”, hanem egy jól megtervezett adatátvétel.

## Előkészítés

### 1. Mentés

Mielőtt bármit átveszel, készíts mentést az új rendszerről. Ha valami rosszul sül el, ide vissza lehet térni.

### 2. Kimentés a régi programból

A MunkaLap 3 modulonként menti ki a táblázatait CSV, TXT vagy XLS formában. A szükséges táblák:

| Régi tábla | Mi van benne? |
|---|---|
| Ügyfél | Név, cím, telefon, e-mail, adószám |
| Tárgy | A készülékek: gyártó, típus, gyári szám, IMEI |
| Munkalap | A javítások: dátum, állapot, ügyfél, készülék |
| Tétel | A munkalapok tételei: munkadíj, alkatrész, ár |
| Hiba | A munkalapok hibái |

A régi **.xls** fájlt nyisd meg egy táblázatkezelőben, és mentsd **.xlsx**-ként vagy **CSV**-ként.

### 3. Excelből jössz?

Ha eddig táblázatban vezetted a javításokat, válaszd szét a lapokat: egy fájl az ügyfeleknek, egy a készülékeknek, egy a munkalapoknak. Minden sornak legyen egyedi azonosítója (például sorszám), mert ezzel kapcsolódik a munkalap az ügyfeléhez. Ha az ügyfél és a javítás egy sorban van, az átvétel előtt érdemes kettébontani.

## Az átvétel menete

A {termeknev} [Adatátvétel](/funkciok/adatatvetel/) lapján az átvétel így megy:

### 1. Fájl kiválasztása és elemzés

Válaszd ki, mit tartalmaz a fájl (például Ügyfelek), és töltsd fel. A rendszer felismeri a kódolást (a régi Windows-os ékezeteket is) és az elválasztót.

### 2. Oszlopok párosítása

Minden mezőhöz a rendszer a fejléc alapján javasol egy oszlopot, mellette mintát mutat. Ellenőrizd; ahol rossz, válaszd ki a jót. A kötelező mezők (például az ügyfél neve és régi azonosítója) csillaggal jelöltek.

### 3. Állapotok párosítása (munkalapoknál)

A régi állapotnevek nem feltétlenül egyeznek az újakkal. Megadhatod soronként, melyik régi állapot melyik új legyen (például `Kiadva = lezart`). Ha üresen hagyod, a rendszer a hasonló nevűre teszi.

### 4. Próba

A próba végigolvassa az egész fájlt, de **semmit nem ír**. Megmutatja:

- hány sor jó,
- hány van már bent,
- hány hibás, és miért (sorszámmal),
- munkalapoknál és tételeknél a bruttó végösszeget,
- melyik állapotba hány lap kerül.

**Hasonlítsd össze a régi program összesítőjével.** Ha a darabszám vagy a végösszeg nem stimmel, most kell megkeresni az okot.

### 5. Éles átvétel

Ha a próba rendben van, indítsd el. A rendszer 500 soronként halad, és mutatja, hol tart. Nagy fájlnál ez percekig is tarthat.

### 6. Ellenőrzés

Nézz meg néhány átvett munkalapot: jó ügyfélhez, jó készülékhez tartozik? Megvannak a tételek? Futtass egy riportot egy régi időszakra, és vesd össze a régi program számaival.

## Amit a rendszer magától intéz

- **Dátumok**: többféle írásmódot felismer (`2019.03.05.`, `05.03.2019`, a táblázatkezelő dátumszáma).
- **Pénzösszegek**: kiveszi a szóközt, a „Ft”-ot, érti a tizedesvesszőt.
- **Munkalapszámok**: megmaradnak, és az új munkalapok a legnagyobb átvett szám után folytatódnak.
- **Feloldó kódok**: titkosítva kerülnek be.
- **Dupla átvétel**: a régi azonosító alapján a már bent lévő sort kihagyja.

## Ha valami nem stimmel

| Tünet | Valószínű ok | Megoldás |
|---|---|---|
| Kérdőjelek az ékezetek helyén | Más kódolás | Mentsd újra „CSV UTF-8” formában |
| „Az ügyfél nincs meg” | Rossz sorrend vagy rossz oszlop párosítva | Előbb az ügyfelek; ellenőrizd a hivatkozó oszlopot |
| „A munkalapszám már foglalt” | Korábbi próbaátvétel maradéka | Vond vissza a korábbi átvételt |
| Kevesebb sor, mint vártad | Hibás sorok | Nézd meg a próba hibalistáját |
| Megszakadt az átvétel | Időtúllépés, bezárt oldal | Indítsd újra: a bent lévőket kihagyja |

## Visszavonás

Ha az átvétel nem sikerült jól, az **Eddigi átvételek** listájában visszavonható: az adott átvétel minden új sora törlődik. Több tábla esetén fordított sorrendben vond vissza (előbb a hibák és tételek, végül az ügyfelek).

## Átállási terv

1. **Egy héttel előtte**: próbaátvétel egy kis mintával, ellenőrzés.
2. **Az átállás előtti estén**: a régi programban lezárod a napot, kimented a táblákat.
3. **Átállás**: mentés, teljes átvétel próbával, ellenőrzés.
4. **Első nap**: minden új ügy az új rendszerben. A régi program csak olvasásra marad.
5. **Egy hónap múlva**: ha minden rendben, a régi programot archiválod.

## A régi pénztár

A régi kasszanyilvántartás külön kérdés. Ha eddig Excelben vezetted a napi zárásokat, a {termeknev} Házipénztár modulja saját importtal rendelkezik a régi „Zárás” táblázathoz: .xlsx-ből vagy CSV-ből betölti, a már rögzített tételeket nem rontja el, és az import visszavonható. Ez független az ügyfelek és munkalapok átvételétől.

## Mi nem jön át?

Érdemes előre tisztázni, mi marad a régi rendszerben:

- a régi program beállításai (sablonok, nyomtatványok) – ezeket az újban kell beállítani,
- a régi felhasználói fiókok – a kollégákat újra fel kell venni,
- a csatolt fájlok, ha a régi program külön mappában tárolta őket.

Ezeket nem érdemes erőltetni. A nyomtatványok beállítása egy délután, a kollégák felvétele néhány perc. A régi programot pedig olvasásra érdemes megtartani egy ideig, ha mégis kellene valami.

## Ellenőrzőlista

- Mentés az új rendszerről
- Régi táblák kimentve (Ügyfél, Tárgy, Munkalap, Tétel, Hiba)
- .xls fájlok .xlsx-re vagy CSV-re mentve
- Próba minden táblára, összesítő összevetve a régivel
- Éles átvétel a helyes sorrendben
- Néhány munkalap szúrópróbaszerűen ellenőrizve
- Riport egy régi időszakra összevetve
- A régi program csak olvasásra

## Összegzés

Az adatátvétel nem varázslat, hanem gondos munka: jó sorrend, próba, összevetés, és egy visszaút, ha valami nem stimmel. Ha ezek megvannak, a váltás egy hétvége alatt lezajlik, és hétfőn a csapat már az új rendszerben dolgozik, a teljes múlttal együtt.

Ha szívesebben bíznád ránk, az adatátvételt szolgáltatásként is vállaljuk; az árak az [Árak](/arak/) oldalon vannak. A {termeknev} felületét a [demóban](/demo/) kipróbálhatod.
