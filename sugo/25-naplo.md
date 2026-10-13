---
kulcs: naplo
cim: Napló (változásnapló)
csoport: Rendszer
modul: naplo
szakaszok: naplo
kulcsszavak: napló, változásnapló, előzmény, előzmények, történet, idővonal, ki módosította, ki írta át, ki törölte, mikor, visszamenőleg, audit, nyomkövetés, log, logolás, naplózás, módosítás, változás, régi érték, új érték, épség, aláírás, CSV, letöltés, ellenőrzés, lezárt, újranyitás
osszefoglalo: A Napló minden módosítást megőriz mezőnként – ki, mikor, honnan, mi volt és mi lett –, és senki nem írhatja át vagy törölheti; egy rekord története a saját Napló gombjáról, a teljes napló a Napló menüpontból látszik.
---
## Mire való

A **Napló** a rendszer változásnaplója: minden, ami a rendszer adatain változik, bekerül – visszamenőleg is látod, ki, mikor, mit és honnan módosított.

Mindent naplóz, mezőnként, a régi és az új értékkel:

- **munkalap, napi árukiadás, rendelés** – a lap minden mezője, a hibák, a tételek (szolgáltatás, termék, díj), a csatolt eszközök, az állapot, a fizetés, a rendelés útja, a lezárás és az újranyitás,
- **üzenetek** – az ügyfélüzenetek (RMA-csevegő), az SMS-ek és a küldési döntések, a csapatüzenetek,
- **üzenőfal** – a cetlik létrehozása, átírása, készre jelölése, levétele (az áthúzás a falon nem számít módosításnak),
- **naptár** – az események és a naptárak (névsor),
- **termékek** – a terméktörzs és minden készletmozgás,
- ügyfelek, eszközök, szolgáltatások, pénztártételek és pénztárnapok, számlák, helyszínek, beszállítók, SMS-sablonok és -szabályok,
- a **Beállítások** változásai (a kulcsok és jelszavak értéke helyett csak annyi, hogy megváltoztak),
- **események**: matrica- és nyomtatványnyomtatás, újranyitás az okával, elutasított szerkesztés lezárt lapon.

Minden bejegyzésben benne van, **honnan** jött a módosítás: a felületről, az AI-asszisztens jóváhagyott javaslatából (a javaslat számával), időzített feladatból, az ügyféloldalról vagy az SMS-átjáróból.

> **Fontos:** a naplót senki nem írhatja át és nem törölheti – a rendszer sem. Minden bejegyzést aláírás véd, és az **Épség ellenőrzése** gomb megmutatja, ha valaki az adatbázisban mégis hozzányúlt.

## Hol találod

- **Egy rekord története:** a munkalap, az árukiadás és a rendelés ablakának alján a **Napló** gomb. Ugyanilyen ablakban nyílik, a lap fölé; minden kolléga látja annak a rekordnak a történetét, amelyet egyébként is lát.
- **A teljes napló:** a bal oldali menü **Napló** pontja, a **Beállítások** fölött. [Napló megnyitása](modul:naplo) Ez adminisztrátori oldal: a kolléga menüjében nem jelenik meg.
- **Beállítások › Változásnapló:** mit naplózzon még a rendszer (beállítások, IP-cím), hány bejegyzés legyen egy oldalon.

## Lépésről lépésre: nézd meg, mi történt egy munkalappal

1. Nyisd meg a munkalapot (vagy az árukiadást, rendelést).
2. Kattints az ablak alján a **Napló** gombra.
3. Az ablakban napokra bontva, a legújabb elöl látod a történetet. Egy kártya egy mentés: fent az időpont, ki csinálta és honnan (például **Felület** vagy **AI-asszisztens**), alatta a változások. Az ablak egyszerre tizenkét bejegyzést mutat, a többit a **Régebbiek** gomb hozza – így az ablak nem nyúlik végeláthatatlanná.
4. A módosított mezőknél áthúzva a régi, félkövérrel az új érték áll: `Állapot: Nyitott → Lezárt`. Széles ablakban a megváltozott mezők egymás mellett, több oszlopban állnak, hogy egy mentés egy pillantással átlátható legyen.
5. Új tételnél vagy törlésnél a **Minden adata** sorra kattintva látod a teljes sort.
6. Ha több bejegyzés van, a **Régebbiek** gomb tölti be a többit.
7. A **Mi történt? – kérdezd az asszisztenst** gomb megkéri a figurát, hogy mondja el időrendben, mi történt a lappal.

## Lépésről lépésre: keresés a teljes naplóban

1. Nyisd meg a **Napló** menüpontot.
2. A szűrőben válaszd ki, mire vagy kíváncsi:
   - **Mi** – munkalapok, árukiadás, rendelések, termékek, üzenetek, csapatüzenetek, üzenőfal, naptár, ügyfelek, eszközök, szolgáltatások, pénztár, számlák, beállítások,
   - **Ki** – egy kolléga,
   - **Honnan** – felület, AI-asszisztens, időzített feladat, ügyféloldal, SMS-átjáró,
   - **Művelet** – létrehozás, módosítás, törlés, esemény, nyomtatás, elutasítva…,
   - **Ettől** / **Eddig** – időszak,
   - **Munkalapszám** – egy lap minden bejegyzése,
   - **Keresés** – név, érték vagy megjegyzés szövegére (például `25000` vagy `Minta Márta`).
3. Kattints a **Szűrés** gombra. Az **Alaphelyzet** törli a szűrőt.
4. A lista alján lapozhatsz. A **CSV letöltése** a szűrt bejegyzéseket Excelben nyitható fájlba menti (soronként egy mezőváltozás).

## Mezők és gombok

| Elem | Mire való |
|---|---|
| **bejegyzés összesen** / **ma** | hány bejegyzés van a naplóban, és ebből ma mennyi |
| **A napló épsége** | az utolsó ellenőrzés eredménye: **Ép** vagy **Eltérés!** |
| **Épség ellenőrzése** | minden bejegyzés aláírását ellenőrzi, és megnézi, nem hiányzik-e közülük |
| **CSV letöltése** | a szűrt bejegyzések letöltése |
| **Kérdezd az asszisztenst** | a figura összefoglalja a (szűrt) naplót idővonalszerűen |
| a kártya színe | zöld: létrehozás, piros: törlés, sárga: elutasítva / védelem, lila: beállítás, kék: módosítás, szürke: esemény, nyomtatás |
| **Felület**, **AI-asszisztens**… | honnan jött a módosítás |

## Az asszisztens és a Napló

Az asszisztens a teljes naplót olvassa:

- Ha egy munkalap (vagy a Napló ablaka) nyitva van, a lap története **magától** megy hozzá – kérdezd meg: `Mi történt ezzel a lappal?`, `Ki írta át a határidőt?`, `Mikor lett lezárva, és ki nyitotta újra?` –, azonnal, idővonalszerűen felel.
- A Napló ablak megnyitásakor a figura fel is ajánlja: **Elmondjam röviden, mi történt vele?**
- A teljes naplóban is keres: `Mit csinált ma Minta Márta?`, `Mi változott tegnap a termékeknél?`, `Ki törölt naptáreseményt a héten?`, `Ki mennyit dolgozott a rendszerben ebben a hónapban?`, `Biztos, hogy nem nyúltak a naplóhoz?`
- A naplót csak olvassa: módosítani, törölni nem tudja – senki sem.
- A zárkódot és a feloldó mintát a naplóból sem kapja meg; a beállítások naplóját csak adminisztrátornak mutatja.

## Beállítások › Változásnapló

A **Beállítások › Változásnapló** lapfülön (a **Rendszer** sorban):

- **A Beállítások változásai is kerüljenek a naplóba** – alapból be van kapcsolva. A titkok (API-kulcs, jelszó, Számla Agent kulcs) értéke soha nem kerül bele, csak hogy megváltoztak.
- **A gép hálózati címe (IP) is kerüljön a bejegyzésbe** – alapból be.
- **Bejegyzés oldalanként** – a Napló menüpont egy oldalán (20–500).

A naplózás maga **nem kapcsolható ki**, és ennek a lapfülnek a változása is bekerül a naplóba.

## Szabályok, amiket a rendszer betart

- **Csak hozzáfűzés.** A napló bejegyzését a rendszer soha nem írja át és nem törli. Ha bárhonnan átírási vagy törlési kísérlet jönne a napló táblájára, a rendszer nem engedi lefutni, és a kísérlet is bekerül (**Védelem**).
- **Aláírás.** Minden bejegyzés a tartalmából és a telepítés titkos kulcsából számolt aláírást kap. Az **Épség ellenőrzése** megmutatja, ha egy bejegyzést utólag átírtak, vagy ha bejegyzések hiányoznak.
- **Mindenhonnan.** A napló az adatbázis szintjén figyel: az űrlap, a lista, az AI-asszisztens, az import és az időzített feladat módosítása is bekerül – nincs kerülőút.
- **Egy mentés = egy kártya.** Ami egy mentéssel változott (a lap mezői, a tételek, a hibák), az egy kártyán látszik.
- **A zajt kihagyja:** a „módosítva” időbélyeg, a cetli helye a falon, az olvasottság és az újraszámolt pénztár-összesítő önmagában nem bejegyzés.
- **Tömeges írás** (például egy régi táblázat importja) egyetlen összesítő bejegyzés a darabszámmal.
- **Privát naptáresemény:** a részletei a naplóban is csak a tulajdonosának látszanak.
- **Közös fiók:** ha a cég egy közös fiókkal dolgozik, a napló a fiók nevét mutatja; névre szóló profilokkal a kolléga nevét.

## Lezárt lap és a Napló

A lezárt (Lezárt, Érvénytelen) lap csak olvasható: szerkeszteni a lap tetején az **Újranyitás** gombbal lehet. A naplóba kerül:

- az újranyitás, az új állapot és az **ok** (ha megadták),
- minden elutasított próbálkozás (például fizetés vagy mentés lezárt lapon) – **Elutasítva** jelzéssel.

Részletek: [Lezárt lap zárolása](sugo:munkalapok).

## Tippek

- Ha valami „magától” változott, nézd meg a **Honnan** oszlopot: időzített feladat, ügyféloldal vagy AI-javaslat is lehetett.
- Napi zárás után a **Mi**: Házipénztár és az aznapi dátum szűrővel látod, ki mit írt át a kasszában.
- Havonta egyszer nyomd meg az **Épség ellenőrzése** gombot.

## Ha valami nem megy

- **Nem látom a Napló menüpontot.** Adminisztrátori oldal. A kolléga a rekordok **Napló** gombjával látja azok történetét.
- **Eltérés! jelzés az épségnél.** Valaki adatbázis-szinten hozzányúlt a naplóhoz (vagy a szerver beállításaiban megváltozott a titkos kulcs). Nézd meg, ki fér hozzá a szerverhez és az adatbázishoz; a hibás bejegyzések azonosítóját az üzenet felsorolja.
- **Egy régi változás nincs benne.** A napló a 0.59.0-s verzió telepítésétől rögzít; ami előtte történt, arról csak az állapottörténet és a pénztár saját naplója tud.

## GYIK

### Ki törölheti a naplót?

Senki. A napló csak bővül: a rendszer sem ír át és nem töröl belőle, a felületen nincs ilyen gomb, és a napló táblájára irányuló törlést a rendszer nem engedi lefutni.

### Látom, ki írta át egy munkalap árát?

Igen. Nyisd meg a lapot, kattints a **Napló** gombra: a tétel módosításánál ott a régi és az új ár, a kolléga neve és az időpont.

### Látszik, ha az asszisztens módosított valamit?

Igen. A bejegyzés forrása **AI-asszisztens**, és a megjegyzésben ott a javaslat száma és leírása. Aki jóváhagyta, annak a neve áll a bejegyzésen.

### Mit jelent az „Elutasítva”?

Valaki lezárt lapon próbált módosítani (mentés, fizetés, eszköz állapota), és a rendszer nem engedte. A lapot előbb újra kell nyitni.

### Lassítja a rendszert a napló?

Mérhetően nem: mentésenként néhány rövid adatbázis-művelettel jár. A nagy, tömeges írásokból egyetlen összesítő bejegyzés lesz.

### A beállítások jelszavai is bekerülnek?

Nem. A titkos értékek (API-kulcs, jelszó, token) helyett csak annyi kerül a naplóba, hogy megváltoztak.

### Exportálhatom a naplót?

Igen, a Napló menüpont **CSV letöltése** gombjával – a szűrt bejegyzéseket, soronként egy mezőváltozással.

### Megkérdezhetem az asszisztenst, mi történt egy lappal?

Igen. Nyisd meg a lapot, és kérdezd meg: `Mi történt ezzel a lappal?` – a lap története magától megy hozzá. A Napló ablak **Mi történt?** gombja ugyanezt kérdezi.

### Mit jelent a „Védelem” bejegyzés?

Valami megpróbálta átírni vagy törölni a napló bejegyzéseit, és a rendszer nem engedte. Nézd meg, ki fér hozzá a szerverhez és az adatbázishoz.

### Miért nem látom a privát naptáresemény részleteit a naplóban?

A privát esemény részletei a naplóban is csak a tulajdonosának látszanak – a többiek annyit látnak, hogy volt változás.

### Benne van, ha valaki újranyit egy lezárt lapot?

Igen: az újranyitás, az új állapot, ki csinálta és az ok (ha megadta) – utána pedig minden módosítás, amit a lapon végzett.

## Túra: naplo

- `.sdh-fejlec` | A Napló | Itt látod a rendszer minden módosítását: ki, mikor, mit és honnan változtatott.
- `.sdh-naplo__statok` | Számok és épség | Hány bejegyzés van, és hogy a napló ép-e. Az **Épség ellenőrzése** gomb most ellenőrzi.
- `.sdh-naplo__szuro` | Szűrő | Mire, kire, honnan, milyen időszakra vagy kíváncsi – és szabad szöveg.
- `.sdh-naplo__lista` | Az idővonal | Egy kártya egy mentés: áthúzva a régi, félkövérrel az új érték.
