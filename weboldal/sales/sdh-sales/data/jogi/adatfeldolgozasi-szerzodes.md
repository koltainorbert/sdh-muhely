---
cim: Adatfeldolgozási szerződés
leiras: A GDPR 28. cikke szerinti feltételek arra a kevés esetre, amikor a szoftver forgalmazója a vevő rendszerében tárolt személyes adathoz hozzáférhet.
verzio: "1.0"
hatalyos: "2026-11-01"
szulo: jogi
---
**TERVEZET – jogásszal ellenőriztetni.**

Hatályos: 2026. november 1-jétől. Verzió: 1.0. Az [Általános szerződési feltételek](/jogi/aszf/) és a [Licencszerződés](/jogi/licencszerzodes/) része; a megrendeléssel létrejön.

## 1. Kiindulópont: a vevő adatai a vevőnél maradnak

A {termeknev} szoftver a Vevő **saját szerverén** fut. A Szoftverben rögzített adatok (ügyfelek, eszközök, munkalapok, pénztár, üzenetek) a Vevő adatbázisában vannak; ezeknek az **adatkezelője a Vevő**. A licencadó ({ceg}, a továbbiakban: Adatfeldolgozó) **rendes működés során ezekhez nem fér hozzá, és azokat nem kapja meg.**

Ez a szerződés a GDPR 28. cikke szerint azokra a kivételes esetekre szól, amikor az Adatfeldolgozó mégis hozzáférhet személyes adathoz.

## 2. Az adatfeldolgozás esetei

| Eset | Mikor | Milyen adat |
|---|---|---|
| **Támogatási hozzáférés** | csak ha a Vevő adminisztrátora engedélyezi, 2 órára, 1 napra vagy 3 napra | mindaz, amit a Szoftverben egy adminisztrátor lát – a hiba vizsgálatához szükséges mértékben megtekintve |
| **Telemetria** | 5 percenként, ha a Vevő licenckulcsot és központot adott meg | műszaki adatok és darabszámok – **személyes adat nincs benne** (lásd Licencszerződés 7. pont); a végzetes hibák üzenetéből az e-mail-címeket és a számsorokat a Szoftver kitakarja, mielőtt elküldi |
| **Távoli karbantartás** | a Vevő által kikapcsolható | adatot nem küld, belépést nem nyit |
| **Tárolás az Adatfeldolgozónál** | **csak ha a Vevő így állítja be** (például a külső mentés célhelye az Adatfeldolgozó által biztosított tárhely) | a mentés jelszóval titkosított; a titkosító jelszót a Vevő adja meg |
| **Adatátvétel, telepítés szolgáltatás** | ha a Vevő ilyet rendel, és ehhez adatot ad át (például régi rendszer exportja) | az átadott fájlban szereplő adatok, csak a munka idejére |

Az Adatfeldolgozó **nem** használja az adatokat saját célra, nem adja el, nem használja AI-modell tanítására, és nem adja tovább – kivéve a 6. pontban írt alfeldolgozókat.

## 3. Az adatkezelő (Vevő) utasításai

Az Adatfeldolgozó kizárólag a Vevő dokumentált utasítása szerint jár el. Utasításnak minősül: a támogatási kérés és engedély, a megrendelt szolgáltatás, a Szoftver beállításai (például távoli karbantartás be/ki). Ha az Adatfeldolgozó szerint egy utasítás jogszabályba ütközik, erről a Vevőt haladéktalanul tájékoztatja.

## 4. Az Adatfeldolgozó kötelezettségei

- titoktartás: a hozzáféréssel rendelkező munkatársak titoktartásra kötelezettek;
- biztonság (GDPR 32. cikk): titkosított kapcsolat, aláírt központ-válaszok, időkorlátos, lejáratkor törlődő támogatói fiók, naplózás a Vevő rendszerében, a mentések titkosítása;
- a támogatás során megismert adatot nem másolja ki a Vevő rendszeréből, kivéve, ha a hiba vizsgálatához elengedhetetlen és a Vevő hozzájárul; az ilyen másolatot a munka végén törli;
- segíti a Vevőt az érintetti kérelmek teljesítésében (a Szoftverben ehhez beépített eszközök vannak, például anonimizálás);
- **adatvédelmi incidenst** indokolatlan késedelem nélkül, legkésőbb a tudomásszerzéstől számított 48 órán belül jelez a Vevőnek [DÖNTENDŐ: határidő], hogy a Vevő a GDPR 33. cikk szerinti 72 órás határidőt tartani tudja;
- a szerződés megszűnésekor az esetleg nála lévő adatot törli, vagy a Vevő kérésére visszaadja;
- a megfelelés igazolásához szükséges információt a Vevő rendelkezésére bocsátja, és lehetővé teszi az ésszerű, előre egyeztetett ellenőrzést.

## 5. A Vevő kötelezettségei

A Vevő felel azért, hogy a Szoftverben kezelt adatok kezelése jogszerű (jogalap, tájékoztatás, megőrzési idő), és hogy támogatási hozzáférést csak szükség esetén, a legrövidebb elegendő időre ad.

## 6. Alfeldolgozók

| Alfeldolgozó | Feladat |
|---|---|
| {tarhely_nev} | a központ és a webhely tárhelye (telemetria tárolása) |
| {levelezo_nev} | a támogatási belépő link és értesítések kézbesítése |

Új alfeldolgozó bevonásáról az Adatfeldolgozó a Vevőt legalább 15 nappal előre e-mailben értesíti; a Vevő tiltakozhat (GDPR 28. cikk (2)). [DÖNTENDŐ]

## 7. Harmadik országba továbbítás

Az Adatfeldolgozó a 2. pont szerinti adatokat az Európai Gazdasági Térségen belül kezeli. [ellenőrizendő: a tényleges tárhely helye]

## 8. Időtartam

Ez a szerződés a licenc fennállásáig, illetve az utolsó adatfeldolgozási művelet befejezéséig hatályos.
