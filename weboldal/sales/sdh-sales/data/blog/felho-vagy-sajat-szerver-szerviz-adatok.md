---
cim: Felhő vagy saját szerver? Hol legyenek a szerviz adatai – előnyök, hátrányok
leiras: Felhős előfizetés, bérelt tárhely vagy gép a boltban? Őszinte összevetés javítóműhelyeknek adattulajdon, elérhetőség, internetkimaradás, költség és üzemeltetés szerint.
kategoria: Biztonság és üzemeltetés
cimkek: [saját szerveres CRM, felhő, szerviz szoftver, adattulajdon]
datum: 2026-09-14
kulcsszo: saját szerveres CRM
seo_cim: Felhő vagy saját szerver? Szerviz adatai összevetve
---

**Röviden:**

- Három fő lehetőség van: **felhős szolgáltatás** (a gyártó szerverén, közösen más cégekkel), **saját példány bérelt tárhelyen**, és **saját példány a bolt helyi szerverén**.
- A felhő a legkényelmesebb, de internet nélkül áll, és az adatok a szolgáltatónál vannak.
- A helyi szerver internet nélkül is működik, az adatok fizikailag nálad vannak, de neked kell gondoskodni a mentésről és az áramellátásról.
- A bérelt tárhelyen futó saját példány a kettő között van: bárhonnan elérhető, de az adatbázis a tiéd.

## Miért fontos ez a döntés?

Amikor szerviz programot választasz, nem csak funkciókat választasz, hanem azt is, hol lesznek az ügyfeleid adatai, mi történik internetkimaradáskor, és mennyire függsz egy szolgáltatótól. Ezeket később nehéz megváltoztatni.

## A három lehetőség

### 1. Felhős szolgáltatás (SaaS)

A program a gyártó szerverén fut, böngészőből éred el, havidíjat fizetsz. Sok nemzetközi szerviz program így működik; a RepairDesk például saját leírása szerint felhőalapú ([blog.repairdesk.co](https://blog.repairdesk.co/?p=15669)).

**Előnyök:**

- nincs telepítés, nincs szerver,
- a frissítést és a mentést a szolgáltató végzi,
- bárhonnan elérhető.

**Hátrányok:**

- internet nélkül nem működik,
- az adatok a szolgáltató szerverén vannak, más cégek adataival egy rendszerben,
- ha az előfizetés megszűnik, a hozzáférés is megszűnhet; az adatokat exportként kapod vissza (ha kapod),
- a szolgáltató dönt az árakról és a feltételekről.

### 2. Saját példány bérelt tárhelyen

A program a te bérelt tárhelyeden vagy virtuális szervereden fut, saját adatbázissal. A licencet a gyártótól veszed, a tárhelyet egy tárhelyszolgáltatótól.

**Előnyök:**

- az adatbázis a tiéd, nem közös más cégekkel,
- bárhonnan elérhető (otthonról, több üzletből),
- az ügyféloldal és az online foglalás kívülről is működik.

**Hátrányok:**

- internet nélkül a bolt nem éri el,
- a tárhelyet neked kell bérelni és fizetni,
- a mentés beállítása a te feladatod (bár a jó program automatizálja).

### 3. Saját példány helyi szerveren

A program egy, a boltban lévő gépen fut, a pultgépek a helyi hálózaton érik el.

**Előnyök:**

- internetkimaradáskor a belső munka megy tovább,
- az adatok fizikailag nálad vannak,
- nincs tárhelydíj.

**Hátrányok:**

- a gépet karban kell tartani, szünetmentes táp ajánlott,
- a mentés külső helyre (felhő, NAS) különösen fontos, mert ha a gép tönkremegy vagy ellopják, minden vele megy,
- az ügyféloldalhoz és az online foglaláshoz a szervernek kívülről is elérhetőnek kell lennie, ami további beállítást igényel.

## Összevetés táblázatban

| Szempont | Felhős szolgáltatás | Bérelt tárhely, saját példány | Helyi szerver |
|---|---|---|---|
| Adatok helye | Szolgáltatónál, közösen | Saját adatbázis a tárhelyen | A boltban |
| Internet nélkül | Nem működik | A bolt nem éri el | A belső munka megy |
| Elérés otthonról | Igen | Igen | Csak külön beállítással |
| Ügyféloldal, foglalás | Igen | Igen | Külön beállítással |
| Telepítés | Nincs | Egyszer | Egyszer |
| Mentés | Szolgáltató | Te (automatizálható) | Te (automatizálható, külső hely kell) |
| Lejárt előfizetés | Szolgáltatótól függ | Licenctől függ | Licenctől függ |

## Mi történik, ha nem fizetsz tovább?

Ez a legfontosabb kérdés, és sokan csak akkor teszik fel, amikor már késő. Felhős szolgáltatásnál a fiók általában zárolódik, és egy exportot kapsz. Saját példánynál a licencfeltételek döntenek. A {termeknev} esetében a lejárt licenc mellett a rendszer tovább működik, minden adattal; csak az új verziók maradnak el. Erről a [GYIK-ban](/gyik/#gyik-mi-van-ha-lejar-a-licenc) is írtunk.

## Mit küld a program a gyártónak?

Saját példánynál is érdemes megkérdezni, mit küld a program a gyártónak. Licencellenőrzés és hibafigyelés miatt a legtöbb program küld valamilyen adatot. A kérdés, hogy csak műszakit (verzió, sebesség, tárhely), vagy ügyféladatot is. A {termeknev} csak műszaki adatot küld; a pontos listát a [GYIK](/gyik/#gyik-mit-kuld-a-rendszer) tartalmazza.

## Melyiket válaszd?

### Válaszd a helyi szervert, ha

- gyakori a bolti internetkimaradás,
- fontos, hogy az adatok fizikailag nálad legyenek,
- van valaki, aki a gépre és a mentésre figyel.

### Válaszd a bérelt tárhelyet, ha

- több üzletből vagy otthonról is dolgoztok,
- az ügyféloldal és az online foglalás fontos,
- nem akarsz a boltban szervert üzemeltetni.

### Válaszd a felhős szolgáltatást, ha

- a legkevesebb üzemeltetési feladatot szeretnéd,
- elfogadod, hogy internet nélkül áll a munka,
- az adatok helye nem elsődleges szempont.

## Ki üzemelteti a saját szervert?

A saját példány nem jelenti azt, hogy a szerviz tulajdonosának rendszergazdának kell lennie. Bérelt tárhelynél a szerver üzemeltetése a tárhelyszolgáltatóé, a program frissítése pedig egy kattintás. Helyi szervernél a gépet valakinek fel kell állítania és időnként rá kell néznie; ezt egy helyi informatikus vagy a szoftver telepítője is elvégezheti. Kérdezd meg a gyártót, kínál-e telepítést, és hogyan értesülsz, ha a szerverrel gond van (például betelik a lemez vagy elmarad a mentés).

## Vegyes megoldás

A bérelt tárhely és a helyi szerver előnyei részben kombinálhatók: például a helyi szerveren fut a rendszer, a mentés pedig titkosítva a felhőbe megy. Így internet nélkül is dolgozhatsz, és ha a gép tönkremegy, a felhőben van egy másolat. Az [offline működés](/funkciok/offline/) oldalon leírtuk, mi kell ehhez.

## Költözés később

Mi van, ha most a helyi szervert választod, de két év múlva nyitsz egy második üzletet, és bérelt tárhelyre költöznél? Egy saját példányos rendszernél ez megoldható: a teljes rendszert (adatbázis és fájlok) átviszed az új szerverre, a licenckulcs ugyanaz marad. Fontos, hogy a szerver titkos kulcsait is változatlanul vidd át, mert a titkosított jelszavak és kulcsok ezekhez kötődnek. Felhős szolgáltatásnál a költözés másik szolgáltatóhoz jellemzően exporttal és importtal történik, ami a két rendszer formátumától függ.

## A biztonság nem a helytől függ

Gyakori tévhit, hogy a felhő eleve biztonságosabb, vagy hogy a saját szerver eleve biztonságosabb. Valójában mindkettő lehet jól vagy rosszul üzemeltetve. Ami mindhárom esetben számít:

- https-es elérés,
- kétlépcsős belépés legalább az adminisztrátoroknak,
- napi mentés és egy külső, titkosított másolat,
- szerepkörök, hogy ki mit lát,
- frissítések telepítése.

## Ellenőrzőlista a döntéshez

- Hol lesznek az adatok, és ki fér hozzájuk?
- Mi működik internet nélkül?
- Mi történik lejárt előfizetésnél vagy licencnél?
- Ki végzi a mentést, és hová?
- Kell-e elérés otthonról vagy több üzletből?
- Kell-e ügyféloldal és online foglalás?
- Ki üzemelteti a szervert?

## Összegzés

Nincs mindenkinek jó válasz. A felhő kényelmes, a helyi szerver független, a bérelt tárhely a kettő között van. A döntésnél a legfontosabb az, hogy tudd: hol vannak az adataid, és mi történik velük, ha valami nem úgy megy, ahogy tervezted.

A {termeknev} saját példányként fut bérelt tárhelyen vagy helyi szerveren. A [demóban](/demo/) kipróbálhatod a felületet, a csomagokat az [Árak](/arak/) oldalon találod.
