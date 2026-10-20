---
kulcs: munkalapclone
cim: Munkalapszoftver Clone – a MunkaLap 3 teljes átvétele
csoport: Rendszer
modul:
szakaszok: munkalapclone
kulcsszavak: munkalapszoftver clone, MunkaLap 3, wdg, mentés átvétele, régi program, adatbázis átvétele, Derby, átalakító, átállás, költözés, import, régi adatok, régi munkalapok, régi ügyfelek, egyedi mezők, párosítás, visszavonás, ellenőrző riport, újraátvétel
osszefoglalo: A Munkalapszoftver Clone modul a MunkaLap 3 teljes mentését (.wdg) veszi át minden adattal – ügyfelek, eszközök, munkalapok állapottörténettel, hibák, tételek, termékek, készlet, képek, e-mailek –, ellenőrző riporttal és visszavonhatóan; ami sehova nem illik, az is megmarad a régi adatok közt.
---

## Mire való

Átálláskor a MunkaLap 3 **teljes** adatbázisa egy lépésben jön át – nem kell táblázatokat kimenteni és egyenként átvenni. A modul a MunkaLap 3 saját mentését (**.wdg** fájl) olvassa, és mindent átvesz belőle:

- **Ügyfelek** a címeikkel (központi, számlázási, levelezési, szállítási cím, telephely), telefonnal, e-maillel, adószámmal, kategóriával.
- **Eszközök** (a MunkaLap „tárgyai”) gyártóval, típussal, IMEI-vel, gyári számmal, színnel, garanciával, vásárlási dátummal.
- **Munkalapok** a régi számukkal, állapottal, felelőssel, határidővel, fizetéssel, megjegyzésekkel és a teljes **állapottörténettel** (ki, mikor tette függőbe, nyitotta meg, készítette el, zárta le).
- **Hibák**, **tételek** (termékek és szolgáltatások áraikkal), **termékek** és **szolgáltatások** (az egyforma nevűek összevonva), **készlet** a régi mozgásokból.
- **Képek és csatolmányok** (munkalap, eszköz, ügyfél), az **elküldött e-mailek** és a mellékleteik.
- Minden más is: a beállítások, a hibakatalógus, a jogosultságok, a számlák – ezek a **Régi adatok** közt maradnak meg, böngészhetően.

A végén **ellenőrző riport** mutatja, hogy minden régi sor megvan-e, és egyezik-e a munkalapok végösszege.

## Hol találod

**Beállítások › Rendszer › Munkalapszoftver Clone**. Csak adminisztrátor látja, és csak akkor, ha a modul be van kapcsolva (Modulok menüpont › Átállás › Munkalapszoftver Clone).

A régi, táblázatos átvétel ([Adatátvétel](sugo:adatatvetel)) továbbra is megvan – arra akkor van szükség, ha nem MunkaLap 3-ból, hanem más programból vagy táblázatból jönnek az adatok.

## Mielőtt elkezded

1. Készíts **mentést** ebben a rendszerben: Beállítások › Mentés › Mentés most.
2. A MunkaLap 3-ban készíts **friss mentést** (Adatbázis › Mentés). Ez egy `.wdg` végű fájl, akár 1 GB-nál is nagyobb.
3. Ha a rendszerben vannak **demó- vagy próbaadatok**, töröld őket (Beállítások › Demó adatok) – különben a régi munkalapszámok egy része foglalt lehet.

> **Tipp:** Az átvétel nem zárja ki, hogy a MunkaLap 3-at közben tovább használjátok. Az átállás napján egy újabb mentést veszel át – az már csak a változásokat írja be.

## Lépésről lépésre

1. **A mentés.** Válaszd ki a `.wdg` fájlt, és **Feltöltés**. A fájl darabokban megy fel, a méretnek nincs korlátja; a csík mutatja, hol tart. Ha a rendszer ugyanazon a gépen fut, ahol a mentés van, a **fájl útvonalát** is beírhatod (például `C:\Users\…\Desktop\MunkaLap Adatbázis (….wdg`) – ekkor nem kell feltölteni.
2. **Előkészítés.** A rendszer magától átalakítja a mentést, kibontja és ellenőrzi (táblánként a darabszámot és egy ellenőrzőösszeget). Ez néhány perc.
3. **Párosítás.** Megmutatja, melyik régi tábla hová kerül, hány sor van benne. Itt nézed át:
   - **Egyedi mezők** – a MunkaLap 3 saját mezői (például „Gyártó”, „IMEI szám”, „Töltő”). A rendszer a nevük alapján javasol helyet; amit „Belső megjegyzésbe” teszel, az a mező nevével együtt a belső megjegyzésbe kerül.
   - **Felhasználók** – kihez kerüljenek a régi felhasználó munkalapjai (felelős, készítő, állapotváltás).
   - **„Vásárlás” lapok** – a MunkaLap 3-ban a javítás nélküli eladást egy „Vásárlás” nevű tárggyal rögzítették. Bekapcsolva ezek **Napi árukiadás** lesznek, eszköz nélkül.
   - **Beállítások kiegészítése** – a régi lapokon használt, itt még hiányzó fizetési módok és a „Nem javítandó” hibaállapot felvétele.
4. **Átvétel indítása** (kétszer kattints). Táblánként, adagokban halad; a csík mutatja, hol tart, és mennyi van még hátra (becslés). Ha bezárod az oldalt, legközelebb onnan folytatja.
5. **Eredmény.** Az ellenőrző riport: hány régi sorból hány van a tárban, mi jött létre (ügyfél, eszköz, munkalap, tétel, fájl…), a régi és az átvett munkalapok végösszege, táblánként a részletek.
6. Ha minden rendben, **Munkafájlok törlése**: a feltöltött mentés és a kibontott csomag törlődik (az átvett adatok és fájlok megmaradnak).

## Az átalakító

A `.wdg` fájlban a MunkaLap 3 saját adatbázisa van (Apache Derby), ezt egy kis átalakító olvassa ki. Az átalakító a MunkaLap 3 **saját** Javáját és adatbázismotorját használja a programmappájából (alapesetben `C:\MunkaLap\MunkaLap-3`) – semmit nem kell telepíteni.

- Ha a rendszer **azon a gépen fut**, ahol a MunkaLap 3 is: minden magától megy. Ha a program máshol van, add meg a mappáját az **Átalakító** részben, és **Mentés és próba**.
- Ha a rendszer **másik gépen** fut: **Átalakító letöltése**. A letöltött mappát bontsd ki a MunkaLap gépén, húzd a `.wdg` fájlt az **Atalakitas.bat**-ra, és a mellé kerülő **…-csomag.zip** fájlt töltsd fel a mentés helyett.

Az eredeti mentéshez az átalakító nem nyúl: egy ideiglenes másolatot olvas, amit a végén töröl.

## Mi hová kerül

| A MunkaLap 3-ban | Itt |
|---|---|
| Ügyfél + címei | Ügyfelek (a központi vagy számlázási cím a számlázási címbe, a többi a levelezési, szállítási címbe és a telephelybe) |
| Tárgy | Eszközök (a tárgy neve – „Mobiltelefon”, „Laptop” – a kategóriát is megadja) |
| Munkalap | Munkalapok a régi számukkal; az állapotváltások a Napló ablakba |
| Munkalap megjegyzése / belső megjegyzése | Megjegyzés (ügyfélnek) / Belső megjegyzés |
| Munkalap tartozékai (Töltő, Doboz…) | a belső megjegyzés „Átvett tartozékok” sora, és az eszköz Tartozékok mezője |
| Hiba | a munkalap Hibák füle (Javítandó → Új, Kijavított → Kész, Nem javítható, Nem javítandó) |
| Tétel | a munkalap Szolgáltatások / Termékek füle; a sztornózott tétel csak a belső megjegyzésben |
| Termék- és szolgáltatástörzs | Termékek, illetve Szolgáltatások (egyforma név = egy szolgáltatás) |
| Készletmozgások | a termékek készletmozgásai; a készlet a régivel egyezően számolódik |
| Képek, csatolmányok | az ügyfél, az eszköz és a munkalap fájljai |
| Elküldött e-mailek és mellékleteik | a munkalap **Fájlok** füle (az e-mail olvasható HTML-fájlként) |
| Minden más (beállítások, hibakatalógus, számlák, jogosultságok…) | **Régi adatok** (böngészhető, a fájlok letölthetők) |

A régi munkalapszám megmarad. Ha egy szám már foglalt (például próbaadat miatt), a lap új számot kap, a régi szám a belső megjegyzésbe kerül. Az új munkalapok a legnagyobb átvett szám után folytatják a számozást.

## Régi adatok

A **Régi adatok** részben minden sor megvan a MunkaLap 3-ból, pontosan úgy, ahogy ott volt. Válaszd ki a táblát, írj be egy azonosítót vagy bármilyen szöveget (nevet, IMEI-t, telefonszámot), és **Mutasd**. Minden sor mellett látod, mi lett belőle itt (például „Munkalap 17219”), vagy miért maradt csak a régi adatok közt. A régi fájlok innen letölthetők.

## Újabb mentés átvétele

Ha a MunkaLap 3-at az átvétel után még használtátok, az átállás napján vegyél át egy friss mentést: az eredmény alatt **Újabb mentés átvétele**. A rendszer soronként összeveti a régivel:

- ami **nem változott**, ahhoz nem nyúl;
- ami **új**, az létrejön;
- ami **megváltozott**, azt a régi program szerint frissíti (az átállásig a régi program a „fő”), és az új állapotváltások a naplóba kerülnek.

## Visszavonás

A **Visszavonás** töröl mindent, amit a modul létrehozott (ügyfelek, eszközök, munkalapok, tételek, hibák, termékek, szolgáltatások, készletmozgások, fájlok), üríti a régi adatok tárát, és visszaállítja a kiegészített beállításokat. Kétszer kell kattintani. A más módon felvett adatokhoz nem nyúl – az átvétel előtt már bent lévő rekordokhoz sem.

## Szabályok, amiket a rendszer betart

- **Semmi nem vész el:** minden régi sor a régi adatok tárába kerül, akkor is, ha nincs itt helye. A riport sorra pontosan egyezteti a régivel.
- **Ellenőrzött csomag:** az átvétel csak ép csomagból indul (táblánként darabszám és ellenőrzőösszeg).
- **Nincs duplázás:** egy régi sorból egy rekord lesz; az újraátvétel a meglévőt frissíti. A táblázatos átvétellel már bent lévő ügyfelet, eszközt, munkalapot összeköti, nem duplázza.
- **Megszakítható:** az adagok egyenként, egészben íródnak be; megszakadás után onnan folytatja, ahol abbamaradt.
- **A feloldó kód titkosítva** kerül az eszközre (ha a régi programban volt ilyen mező).
- A régi felhasználók **jelszavai nem** jönnek át, és a **bélyegképek** sem (a képekből újra elkészülnek).
- Az átvétel a **Változásnaplóba** egy-egy eseményként kerül (indulás, befejezés, visszavonás).

## Ha valami nem megy

- **„Nem találom a MunkaLap 3 programmappáját”** – add meg az Átalakító részben (amelyikben a `lib\derby.jar` van), vagy használd a letölthető átalakítót a MunkaLap gépén.
- **„Az átalakító nem indult el a szerveren”** – a szerver nem futtathat programot, vagy nem éri el a MunkaLap mappáját. Töltsd le az átalakítót, és futtasd a MunkaLap gépén; a csomagot töltsd fel.
- **„A csomag hibás”** – a feltöltés vagy a mentés sérült. Készíts új mentést a MunkaLap 3-ban, és kezdd újra.
- **Lassú** – nagy adatbázisnál (40 000 munkalap) az átvétel akár fél óra is lehet; ez a gép sebességétől függ. Hagyd nyitva az oldalt; ha bezárod, legközelebb folytatja.
- **Figyelmeztetések** – az átvétel közben előforduló hibák a csík alatt, a **Figyelmeztetések** részben látszanak; a riport ilyenkor jelzi, ha egy tábla nem teljes.

## GYIK

### Mit jelent a „Munkalapszoftver Clone”?

A MunkaLap 3 teljes adatbázisának átvételét: minden adat és fájl egy lépésben átjön, és a régi munkalapok a régi számukkal folytatódnak itt.

### Milyen fájlt kell feltöltenem?

A MunkaLap 3 mentését (`.wdg`), amit a MunkaLap 3-ban az Adatbázis › Mentés készít, vagy az átalakító által készített `…-csomag.zip` fájlt.

### Mekkora lehet a fájl?

Bármekkora: darabokban megy fel. Egy 40 000 munkalapos adatbázis mentése kb. 1 GB.

### Kell Javát telepítenem?

Nem. Az átalakító a MunkaLap 3 saját Javáját használja a programmappájából.

### Mi történik, ha a rendszer nem azon a gépen fut, ahol a MunkaLap 3?

Töltsd le az átalakítót, futtasd a MunkaLap gépén (húzd a `.wdg`-t az Atalakitas.bat-ra), és a kész csomagot töltsd fel.

### Megmaradnak a régi munkalapszámok?

Igen. Ha egy szám már foglalt, az a lap új számot kap, és a régi szám a belső megjegyzésbe kerül.

### Átjön az, hogy ki és mikor zárta le a munkalapot?

Igen: az állapotváltások (bejelentve, árajánlat, függő, nyitott, elkészült, lezárva, érvénytelen) a munkalap naplójába kerülnek, a felhasználó párosítása szerint.

### Mi lesz a MunkaLap 3 egyedi mezőivel?

A párosításban megadod, hová kerüljenek (például „IMEI szám” → IMEI). Amit nem párosítasz, az a mező nevével a belső megjegyzésbe kerül, és a régi adatok közt is megvan.

### Hová kerülnek az elküldött e-mailek?

A munkalap Fájlok fülére, olvasható HTML-fájlként, a mellékleteikkel együtt. Az ügyféloldalon nem jelennek meg.

### Mi lesz a „Vásárlás” nevű tárgyakkal?

Bekapcsolt kapcsolónál a hozzájuk tartozó lapok Napi árukiadásként jönnek át, eszköz nélkül.

### Mi lesz a sztornózott tételekkel?

A régi végösszegben sem szerepeltek, ezért itt sem lesznek tételek; a munkalap belső megjegyzésébe kerülnek, és a régi adatok közt is megvannak.

### Egyezik a készlet a régivel?

Igen: a készletet a régi mozgásokból számolja; ahol eltérne, egy „egyeztetés” korrekciós mozgás teszi egyenlővé.

### Mennyi ideig tart?

A géptől függ: egy 40 000 munkalapos adatbázis átvétele kb. 10–30 perc (becslés). A csík a hátralévő időt is mutatja.

### Bezárhatom közben az oldalt?

Igen. Az átvétel adagokban halad, és ha újra megnyitod ezt a lapot, onnan folytatja, ahol abbamaradt.

### Át lehet venni később egy újabb mentést?

Igen, az átállás napján is: csak a változások íródnak be, a már átvett adatok nem duplázódnak.

### Visszavonhatom az átvételt?

Igen, a Visszavonás gombbal (kétszer kattints). Mindent töröl, amit a modul létrehozott; a más módon felvett adatokhoz nem nyúl.

### Hol látom azt, aminek itt nincs helye?

A Régi adatok részben: minden tábla minden sora kereshető, a fájlok letölthetők.

### Átjönnek a régi jelszavak?

Nem. A régi felhasználók itt a párosításban megadott felhasználókhoz kerülnek; belépni a saját fiókjukkal tudnak.

### Kikapcsolhatom a modult az átvétel után?

Igen (Modulok › Munkalapszoftver Clone). Az átvett adatok megmaradnak; visszakapcsolva a régi adatok tára is újra böngészhető.
