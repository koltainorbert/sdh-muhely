---
cim: GDPR egy javítóműhelyben: meddig tárolhatod az ügyféladatot, és mit jelent az anonimizálás?
leiras: Mit kér a GDPR egy kis szerviztől? Adattakarékosság, tárolási idő, törlési kérelem és anonimizálás, a számviteli megőrzéssel összhangban. Gyakorlati útmutató, nem jogi tanács.
kategoria: Garancia és jog
cimkek: [GDPR szerviz ügyféladat, anonimizálás, adatvédelem, törlési kérelem]
datum: 2026-08-31
kulcsszo: GDPR szerviz ügyféladat
seo_cim: GDPR javítóműhelyben: ügyféladat, törlés, anonimizálás
---

**Röviden:**

- A GDPR szerint csak annyi személyes adatot gyűjts, amennyi a célhoz szükséges, és csak addig tárold azonosítható formában, ameddig szükséges.
- Az ügyfélnek joga van kérni az adatai törlését, de ez nem vonatkozik arra, amit jogszabály alapján meg kell őrizned (például a számviteli bizonylatokat).
- A megoldás gyakran az **anonimizálás**: a személyes adatok törlődnek, a munkalap és a számla személyes azonosító nélkül megmarad.
- A szervizes különlegesség: a **feloldó kód és minta** nagyon érzékeny adat, titkosítva kell tárolni, és csak jogosultnak megmutatni.
- Ez a cikk tájékoztató, nem jogi tanácsadás. Az adatkezelési tájékoztatódat jogásszal készítsd el.

## Milyen személyes adatot kezel egy szerviz?

Többet, mint elsőre gondolnánk:

- név, telefonszám, e-mail, cím,
- céges ügyfélnél kapcsolattartó neve,
- a készülék azonosítói (IMEI, gyári szám), amelyek egy személyhez köthetők,
- a feloldó kód vagy minta,
- az ügyféllel váltott üzenetek, SMS-ek,
- átvételi fotók, aláírás,
- a javítás tartalma, amely néha érzékeny információt is elárul.

Ráadásul a készüléken lévő adatokhoz (fotók, üzenetek) a szerelő a javítás során hozzáférhet. Ezt az adatkezelési tájékoztatóban és a belső szabályokban is kezelni kell.

## A GDPR alapelvei, szervizes nyelven

A GDPR 5. cikke ([gdpr-info.eu](https://gdpr-info.eu/art-5-gdpr/)) több alapelvet rögzít. Kettő különösen fontos egy szerviznek:

### Adattakarékosság

Az adatoknak „a célhoz szükséges mértékre” kell korlátozódniuk (5. cikk (1) c) pont). Szervizes nyelven: ne kérd el az ügyfél születési dátumát, ha a javításhoz nem kell. A telefonszám kell az értesítéshez, a cím kellhet a számlához, de nem minden mező kötelező minden ügyfélnél.

### Korlátozott tárolhatóság

Az adatok azonosításra alkalmas formában csak a cél eléréséhez szükséges ideig tárolhatók (5. cikk (1) e) pont). Szervizes nyelven: egy tíz éve járt ügyfél nevét és telefonszámát nem feltétlenül kell örökre megőrizni, ha nincs rá szükség.

## Törlési kérelem: mit tehetsz, és mit nem?

A GDPR 17. cikke ([gdpr-info.eu](https://gdpr-info.eu/art-17-gdpr/)) szerint az érintettnek joga van kérni a rá vonatkozó személyes adatok törlését. A 17. cikk (3) bekezdése ugyanakkor kivételeket is tartalmaz, többek között ha az adatkezelés uniós vagy tagállami jog szerinti **jogi kötelezettség teljesítéséhez** szükséges.

Ilyen kötelezettség lehet a számviteli bizonylatok megőrzése. A hazai számviteli szabályok szerint a könyvviteli elszámolást alátámasztó bizonylatokat hosszú ideig (az [Adózóna összefoglalója](https://adozona.hu/adozas_rendje/Milyen_iratokat_kell_megorizni_es_mennyi_id_7V2BTE) szerint legalább 8 évig) olvasható formában meg kell őrizni. A pontos megőrzési időket a könyvelőddel egyeztesd.

Vagyis: ha egy ügyfél kéri az adatai törlését, a számlát nem törölheted, de a nem szükséges személyes adatokat igen.

## Anonimizálás a gyakorlatban

Az anonimizálás azt jelenti, hogy a személyes adatok úgy törlődnek, hogy a rekord (munkalap, számla, pénztártétel) megmarad, de már nem köthető az ügyfélhez. Egy szervizes anonimizálásnál például:

| Törlődik | Marad |
|---|---|
| Név (helyette pl. „Anonimizált ügyfél”) | Ügyfélszám |
| Telefonszámok, e-mail | Munkalapok (személyes adat nélkül) |
| Címek | Számlák, pénztártételek |
| Megjegyzések | Eszközök (azonosító nélkül) |
| Feloldó kód és minta | Statisztikai adatok |
| SMS-ek telefonszáma, igény szerint az üzenetek szövege | |

A {termeknev} ügyfél-adatlapján az [anonimizálás](/funkciok/biztonsag/) egy gombbal indítható: megmutatja, mi törlődik és mi marad, kötelező rögzíteni a kérés tényét, és kérhető visszaigazoló e-mail. A naplóba egyetlen bejegyzés kerül személyes adat nélkül, és a régi adatok egy későbbi újraimporttal sem jönnek vissza.

## A feloldó kód: a legérzékenyebb adat

A képernyőzár kódja vagy mintája hozzáférést ad a készülék teljes tartalmához. Ezért:

- ne írd cetlire vagy a munkalap nyomtatott részére,
- tárold titkosítva,
- csak az lássa, akinek a javításhoz szükséges, és minden megnézés naplózódjon,
- a javítás után, ha nincs rá szükség, töröld.

A GDPR 32. cikke ([gdpr-info.eu](https://gdpr-info.eu/art-32-gdpr/)) a megfelelő biztonsági intézkedések között kifejezetten említi a személyes adatok **álnevesítését és titkosítását** (32. cikk (1) a) pont), valamint azt a képességet, hogy fizikai vagy műszaki incidens esetén az adatokhoz való hozzáférés kellő időben **visszaállítható** legyen (32. cikk (1) c) pont). Ez utóbbi a mentésről szól, amelyről a [kétlépcsős azonosításról és mentésről szóló témánál](/funkciok/biztonsag/) írunk.

## Ki fér hozzá az adatokhoz?

### A csapaton belül

Nem mindenkinek kell mindent látnia. A pultos lássa az ügyfél elérhetőségét, a szerelő a készüléket és a hibát, a pénzügyi adatokat csak az, akinek ez a dolga. Szerepkörökkel ez beállítható.

### A szoftver gyártója

Kérdezd meg, a szoftver küld-e adatot a gyártónak, és ha igen, mit. A {termeknev} a licencközpontnak csak műszaki adatot küld (példányazonosító, verzió, működési mérőszámok), ügyféladatot nem. A támogató csak a te engedélyeddel, időkorláttal léphet be.

### Külső szolgáltatások

Ha SMS-t, e-mailt, AI-asszisztenst vagy felhős mentést használsz, ezek is adatfeldolgozók lehetnek. Az adatkezelési tájékoztatóban ezeket fel kell tüntetni.

## Mit tegyél, ha törlési kérelem érkezik?

1. **Azonosítsd a kérelmezőt.** Győződj meg róla, hogy valóban az ügyfél kéri, ne egy idegen.
2. **Rögzítsd a kérést**: mikor, milyen csatornán, mit kért.
3. **Nézd meg, mi tartozik hozzá**: ügyféladat, készülékek, munkalapok, üzenetek, SMS-ek.
4. **Döntsd el, mi marad**: a számviteli bizonylatok és a jogi kötelezettség miatt megőrzendő adatok.
5. **Hajtsd végre az anonimizálást**, és ha kérte, küldj visszaigazolást.
6. **Ne felejtsd el a mentéseket**: a régi mentésekben az adat még szerepel. Ezek a megőrzési idő lejártával cserélődnek; az adatkezelési tájékoztatóban érdemes ezt is leírni.

A GDPR szerint a kérelemre indokolatlan késedelem nélkül kell reagálni. A pontos határidőket és a kivételeket jogászod tudja megmondani.

## Átvételi fotók és aláírás

Az átvételkor készült fotón néha a készülék kijelzője is látszik, rajta értesítésekkel, nevekkel. Fotózáskor ügyelj arra, hogy a képernyőn ne legyen személyes tartalom, vagy a kijelző legyen kikapcsolva, ha az állapota nem lényeges. Az aláírás is személyes adat; csak a munkalaphoz tartozzon, ne kerüljön máshová.

## Ellenőrzőlista

- Adatkezelési tájékoztató elkészítve, jogász átnézte
- Az átvételi lapon és a foglalóoldalon hivatkozás a tájékoztatóra
- Csak a szükséges mezők kötelezők
- Feloldó kód titkosítva, megnézés naplózva
- Szerepkörök: ki mit lát
- Törlési kérelem esetén anonimizálás, a kérés rögzítve
- Számviteli megőrzési idők egyeztetve a könyvelővel
- Napi mentés, külső titkosított másolat
- Adatfeldolgozók listája naprakész

## Összegzés

A GDPR egy szerviznek nem a papírmunkáról szól, hanem arról, hogy az ügyfél adatai biztonságban legyenek, és ne tárold őket feleslegesen. Az anonimizálás jó egyensúly: az ügyfél kérése teljesül, a jogszabály szerint megőrzendő bizonylatok pedig megmaradnak.

A {termeknev} [adatbiztonsági funkcióit](/funkciok/biztonsag/) a [demóban](/demo/) is megnézheted. Kérdéseid vannak az adatkezelésről? Nézd meg a [GYIK adatbiztonsági részét](/gyik/#gyik-hol-vannak-az-adatok).

*Források: GDPR 5., 17. és 32. cikk (gdpr-info.eu); Adózóna: Milyen iratokat kell megőrizni és mennyi ideig. A cikk tájékoztató jellegű, nem minősül jogi tanácsnak.*
