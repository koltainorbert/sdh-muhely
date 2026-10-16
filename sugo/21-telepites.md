---
kulcs: telepites
cim: Telepítés és frissítés
csoport: Rendszer
csak: fejleszto
modul:
szakaszok:
kulcsszavak: telepítés, telepít, frissítés, frissít, frissítés elérhető, GitHub, frissítő gomb, automatikus frissítés, új verzió, verzió, zip, bővítmény, plugin, WordPress, PHP, követelmény, bekapcsolás, aktiválás, cím, URL, időzóna, SMTP, levélküldés, élesítés, költöztetés, kikapcsolás, törlés
osszefoglalo: Mit kíván a rendszer, hogyan telepíted egy külön belső WordPressbe, hogyan frissíted egy kattintással a GitHubról vagy a teljes zip feltöltésével úgy, hogy az adatok megmaradnak, és mit próbálj ki élesítés előtt.
---

## Mire való

Ez a fejezet az adminisztrátornak és az üzemeltetőnek szól: mi kell a rendszer futtatásához, hogyan kerül fel, hogyan frissíted, és mit ellenőrizz élesítés előtt.

A rendszer egy WordPress-bővítmény. A mappája `sdh-muhely`, a műhely-felület a WordPress címe alatt, a `/muhely/` útvonalon fut. A WordPress itt csak háttér: a műhely-felület nem használja a sablont, saját teljes képernyős kerete van.

## Követelmények

| Mi kell | Mennyi |
|---|---|
| WordPress | 6.4 vagy újabb |
| PHP | 8.1 vagy újabb |
| Adatbázis | amit a WordPress használ (MySQL vagy MariaDB) |
| Böngésző | friss Chrome, Edge, Firefox vagy Safari |

A PHP részéről ezekre is szükség van – a szokásos tárhelyeken mind megvan:

- **sodium** – a jelszavak és kulcsok titkosításához (Levelezés, Asszisztens),
- **zlib (gzip)** – a teljes mentéshez és az irányítószám-listához,
- **mbstring** – az ékezetes szövegek kezeléséhez,
- **ZipArchive és XMLReader** – a Pénztár Excel-importjához.

A szervernek el kell érnie az internetet a levelezéshez, a Számlázz.hu-számlázáshoz, az asszisztenshez és a fizetős IMEI-lekérdezéshez. A felvitel, a munkalap, a pénztár és a helyi nyomtatvány enélkül is megy.

> **Fontos:** A rendszert `https://` címen futtasd. Titkosítatlan címen a jelszavak és az ügyféladatok védtelenül utaznak.

## Telepítés külön belső WordPressbe

A rendszer **önálló, belső WordPress-telepítésbe** való – nem a cég nyilvános weboldalára. Legyen saját, elzárt helye: külön aldomain (például `muhely.pelda.hu`) vagy a helyi hálózaton futó szerver.

1. Telepíts egy üres WordPresst a kiválasztott címre. Más bővítmény és különleges sablon nem kell hozzá.
2. Lépj be a WordPress vezérlőpultjába adminisztrátorként.
3. Nyisd meg: **Bővítmények › Új bővítmény › Bővítmény feltöltése**.
4. Válaszd ki a rendszer telepítő zip fájlját, és nyomd meg a **Telepítés most** gombot.
5. A telepítés végén kapcsold be a bővítményt.

Bekapcsolás után a rendszer magától:

- létrehozza a saját adattábláit (a WordPress előtagja után `sdh_` kezdetűek),
- feljegyzi a telepítés idejét és a verziót (`sdh_muhely_telepitve`, `sdh_muhely_version`),
- bejegyzi a `/muhely/` útvonalat,
- létrehozza a **Kolléga** szerepkört (`sdh_kollega`).

Újraaktiváláskor adat nem vész el.

## A műhely-felület címe

A kollégák nem a WordPress vezérlőpultjában dolgoznak, hanem a műhely-felületen.

- **Szép címekkel:** `https://muhely.pelda.hu/muhely/`
- **Szép címek nélkül:** `https://muhely.pelda.hu/?muhely=attekintes` – ez mindig működik.

A pontos címet a **Beállítások › Névjegy** lapfül **Műhely-felület** sora írja ki. A WordPress vezérlőpultjában a rendszer menüpontjának (kalapács ikon) áttekintő oldalán a **Megnyitás a műhely-felületen** gomb visz át.

> **Tipp:** A WordPressben a **Beállítások › Közvetlen hivatkozások** alatt válassz szép címformát (ne a kérdőjeles alapformát). A `/muhely/` útvonalat a rendszer maga jegyzi be és tartja karban.

## Első adminisztrátor és a kollégák

- **Az első adminisztrátor** az, aki a bővítményt telepítette. Minden WordPress-adminisztrátor a rendszerben is adminisztrátor.
- **A kollégákat** a műhely-felületen, a **Csapat** menüpontban veszed fel. Az új kolléga a **Kolléga** szerepet kapja, és belépés után egyenesen a műhelybe kerül. Részletek: [Csapat](sugo:csapat).
- **Közös fiókkal** is használható a rendszer: lásd [Mentés, jogosultság, biztonság](sugo:mentes-biztonsag).

## A WordPress időzónája

A rendszer minden időpontot a WordPress időzónája szerint kezel: az órát, a nyitvatartást, a munkalapok dátumát, a pénztár napjait, a naptárat.

1. A WordPress vezérlőpultjában nyisd meg: **Beállítások › Általános**.
2. Az **Időzóna** mezőben válaszd ki a várost: **Budapest**.
3. Mentsd el.

Ellenőrzés: a műhely-felületen a **Beállítások › Nyitvatartás és óra** lapfül kiírja a rendszer mostani idejét és az időzónát.

> **Figyelem:** Az időzónát az első éles munkalap előtt állítsd be. Utólagos váltásnál a korábban rögzített időpontok nem íródnak át.

## Levélküldés (SMTP) az értesítésekhez

A rendszer kétféle úton küld levelet:

- **A Levelezés menüpont** a bekötött postafiók saját kimenő szerverén küld, a WordPress levélküldésétől függetlenül. Részletek: [Levelezés](sugo:levelezes).
- **Az értesítők** a WordPress levélküldőjén mennek ki. Ilyenek:
  - az ügyfélnek szóló e-mail a munkalapjához írt üzenetről,
  - a szerviznek szóló értesítés, ha az ügyfél üzent (a címe a **Beállítások › Ügyféloldal (RMA)** lapfülön: **Ügyfélüzenetek ide érkeznek (e-mail)**),
  - a naptár emlékeztetői és a napi összefoglaló,
  - az új kolléga belépési linkje.

A WordPress alapból a szerver saját levélküldőjét használja, amelynek leveleit a nagy levelezők gyakran levélszemétnek minősítik. Ezért az értesítőkhöz állíts be a WordPressben SMTP-küldést egy erre való bővítménnyel, a cég valódi postafiókjának adataival.

A naptár e-mail értesítései a WordPress időzített feladataival (WP-Cron) futnak, ötpercenként. Ez alapból akkor lép működésbe, amikor valaki megnyit egy oldalt; ha zárva tartás alatt is pontos értesítés kell, az üzemeltető állítson be hozzá valódi ütemezett feladatot.

## Zárt mód (a fejlesztőnek)

A telepítés csak a műhelyé, ezért a rendszer nem mutat mást: a kezdőlap, bármilyen más vagy nem létező cím a műhelybe visz, kijelentkezve a saját **bejelentkező oldalra** (`/muhely/belepes/`). A böngészőfülön a rendszer saját ikonja áll, a `robots.txt` mindent tilt, a háttérrendszer programozói felületei kívülről nem válaszolnak, és a gyökérben álló `readme.html` / `license.txt` naponta törlődik. Ha mégis kellene a sima weboldal: a `wp-config.php`-ba `define('SDH_MUHELY_NYITOTT_OLDAL', true);`.

## Frissítés egy kattintással (GitHubról)

**Csak a fejlesztőnek látszik** (a teljes jogú fiók): az adminisztrátor és a többi szerep nem látja a Frissítés dobozt és az értesítő sávot.

A rendszer magától megnézi, van-e újabb verzió a GitHubon (kb. félnaponta, és amikor te kéred). Ha van, a **Beállítások** minden lapfülének tetején megjelenik: **Frissítés elérhető: …**, a WordPress **Bővítmények** oldalán pedig a szokásos „Új verzió érhető el” jelzés.

### Lépésről lépésre: frissítés a gombbal

1. Nyisd meg a **Beállítások › Névjegy** lapfület. A **Frissítés** dobozban látod a telepített és a legújabb verziót.
2. Ha nem biztos, hogy friss az adat, nyomd meg az **Ellenőrzés most** gombot.
3. Nyomd meg a **Frissítés most** gombot. A gomb kétlépcsős: az első kattintás megerősítést kér (**Biztosan? Kattints újra**), a második indít.
4. A rendszer **először teljes mentést készít** (a neve `…-frissites-elott-…`), és csak utána cseréli a fájlokat. Ha a mentés nem sikerül, nem frissít.
5. Az oldal magától újratöltődik. A **Plugin verzió** sor az új számot mutatja.

Ami közben történik:

- **Az adatok megmaradnak**, mint a zipes frissítésnél. Az adatbázis az első oldalnál magától igazodik.
- A csere néhány másodpercig tart; ez alatt a WordPress rövid „karbantartás alatt” oldalt mutathat. Frissíts nyitás előtt vagy zárás után.
- Ha a csere bármiért elakad, a régi verzió marad, és a gomb mellett az ok olvasható.

**Mikor nem működik a gomb:**

- **Nincs internet.** Az ellenőrzés csendben kihagyódik, a munka nem akad meg; a hibaüzenet kiírja, hogy a GitHub nem érhető el. Később próbáld újra.
- **Fejlesztői példány.** Ha a bővítmény mappájában git-tároló van (a fejlesztő gépén), a gomb nem frissít, mert felülírná a munkát: ott `git pull` a rend.
- **A szerver nem írhatja a bővítmény mappáját.** Ilyenkor a tárhely üzemeltetője tud segíteni; addig a zipes frissítés használható.

> **Figyelem:** A GitHubon a `main` ágon álló verzió a „legújabb”. Más tárolóból vagy ágról a fejlesztő állíthatja át a forrást.

## Lépésről lépésre: frissítés zippel

Ha a gomb nem használható, a frissítés a **teljes zip** feltöltése. Nem kell előtte semmit törölni vagy kikapcsolni.

1. **Ments.** A műhely-felületen: **Beállítások › Asszisztens › Mentések › Mentés most**. Nagyobb frissítés előtt a szerver teljes mentése is legyen kéznél.
2. A WordPress vezérlőpultjában nyisd meg: **Bővítmények › Új bővítmény › Bővítmény feltöltése**.
3. Válaszd ki az új verzió zip fájlját, és nyomd meg a **Telepítés most** gombot.
4. A WordPress jelzi, hogy ez a bővítmény már telepítve van, és megmutatja a mostani és a feltöltött verziót. Válaszd a meglévő lecserélését a feltöltöttel.
5. Nyisd meg a műhely-felületet. Az első megnyitott oldalnál a rendszer magától elvégzi, ami a frissítéshez kell.
6. Ellenőrizd a **Beállítások › Névjegy** lapfülön, hogy a **Plugin verzió** az új szám.

Ami a frissítéskor magától megtörténik:

- **Az adatok megmaradnak.** A csere csak a bővítmény fájljait érinti; az adattáblák, a beállítások, a csatolt fájlok és a mentések nem változnak.
- **Az adatbázis magától frissül.** Ha az új verzió új táblát vagy mezőt hoz, a rendszer az első megnyitott oldalnál – a műhely-felületen vagy a vezérlőpultban – hozzáigazítja az adatbázist. A telepített sémaverziót a `sdh_muhely_db_version` opció őrzi.
- **A `/muhely/` útvonal újra bejegyződik.** A közvetlen hivatkozásokat nem kell kézzel menteni.
- **A nyitva hagyott oldalak maguktól megújulnak:** a böngésző észreveszi az új verziót, és szükség esetén egyszer újratölti az oldalt.
- **Az asszisztens megtanulja az újdonságokat;** a **Súgó › Újdonságok** oldalon is olvashatók.

> **Tipp:** Frissíts nyitás előtt vagy zárás után, amikor senki nem ment éppen.

## Szabályok, amiket a rendszer betart

- **A telepítés és a frissítés nem veszít adatot.**
- **A kikapcsolás nem töröl semmit.** Visszakapcsolás után minden ott folytatódik.
- **A bővítmény törlése sem törli az adatokat.** A rendszernek nincs eltávolító rutinja: a WordPress csak a fájlokat törli, az adattáblák és az `sdh_muhely_` kezdetű beállítások az adatbázisban maradnak.
- **A feltöltött fájlok védett mappákban vannak** a WordPress feltöltési mappája alatt: `sdh-muhely/csatolmany/` (csatolt fájlok), `sdh-muhely-mentes/` (mentések), `sdh-muhely-levelek/` (a levelek titkosított gyorsítótára). Mindegyiket `.htaccess` fájl zárja le.

> **Figyelem:** A mappák védelme `.htaccess` fájlra épül. Ha a szervered ezt nem veszi figyelembe (például nginx), kérd az üzemeltetőt, hogy ezt a három mappát a szerver beállításában zárja le.

## Költöztetés másik szerverre

Költöztetéskor a teljes WordPresst vidd át: az adatbázist és a fájlokat is, a feltöltési mappával együtt.

- A `wp-config.php` titkos kulcsait (a *salt* értékeket) vidd át változatlanul. A titkosítva tárolt jelszavak ezekhez kötődnek: ha megváltoznak, az alkalmazásjelszót és az API-kulcsokat újra be kell írni.
- Költözés után nézd meg a **Névjegy** lapfület, és próbáld ki a levelezés **Kapcsolat ellenőrzése** gombját.

## Mit próbálj ki élesítés előtt

Menj végig ezen a listán egy próbaügyféllel:

1. **Belépés** adminisztrátorként és egy kolléga fiókjával is. A kolléga ne lássa a Beállításokat.
2. **Idő:** a fejléc órája a helyes időt mutatja, a nyitvatartás stimmel.
3. **Számozás:** a **Beállítások › Számozás** lapfülön a kezdőérték az, ahonnan folytatni akarjátok.
4. **Teljes kör:** új ügyfél, eszköz, munkalap, állapotváltás, fizetés, pénztárzárás. Fejezet: [Első lépések](sugo:elso-lepesek).
5. **Nyomtatás:** a munkalap és a címke kijön a nyomtatón.
6. **Számla:** a **Beállítások › Számlázás** lapfülön a **Kapcsolat ellenőrzése** sikeres.
7. **Ügyféloldal:** a munkalap QR-kódjával megnyílik, és az üzenet oda-vissza megérkezik.
8. **Értesítő levél:** megjön, és nem a levélszemétbe kerül.
9. **Levelezés:** a postafiók megnyílik, egy próbalevél kimegy.
10. **Mentés:** a **Mentés most** lefut, a fájl letölthető; és megvan a szerver saját, napi mentése.

> **Figyelem:** A demó adatok az éles rendszerben is ott maradnának, és munkalapszámokat foglalnak. A próbát külön próbatelepítésen végezd. Lásd: [Beállítások](sugo:beallitasok).

## Ha valami nem megy

- **A `/muhely/` cím „nem található" oldalt ad.** A rendszer a hiányzó útvonalat magától pótolja; töltsd újra az oldalt. Ha nem segít, a vezérlőpultban nyisd meg a **Beállítások › Közvetlen hivatkozások** oldalt, és mentsd el változtatás nélkül. A `?muhely=attekintes` végű cím addig is működik.
- **A feltöltés túl nagy fájlra hivatkozva megáll.** A zip nagyobb, mint a szerver feltöltési korlátja; az üzemeltető tudja megemelni.
- **Bekapcsoláskor a WordPress hibát jelez.** Ellenőrizd a PHP verzióját: 8.1 alatt a bővítmény nem indul el.
- **Frissítés után régi a felület.** Nyomd meg a [[Ctrl]]+[[F5]] billentyűket. Ha a **Plugin verzió** is a régi, a csere nem történt meg – ismételd meg a feltöltést.

További elakadások: [Hibaelhárítás](sugo:hibaelharitas).

## GYIK

### Feltehetem a cég nyilvános weboldalára?

Ne tedd. Belső ügyféladatokat kezel, ezért külön, elzárt WordPress-telepítésbe való.

### Milyen WordPress és PHP kell hozzá?

WordPress 6.4 vagy újabb, PHP 8.1 vagy újabb.

### Mi a műhely címe telepítés után?

A WordPress címe, a végén `/muhely/`. A pontos címet a **Beállítások › Névjegy** lapfül kiírja.

### Hogyan frissítek új verzióra?

Legegyszerűbben a **Beállítások › Névjegy** lapfülön: **Ellenőrzés most**, majd **Frissítés most** (kétlépcsős, előtte mentés készül). Zippel, a WordPress vezérlőpultjában: **Bővítmények › Új bővítmény › Bővítmény feltöltése**. Töltsd fel a teljes zipet, és válaszd a meglévő lecserélését.

### Honnan tudom, hogy van újabb verzió?

A Beállítások lapfülei fölött megjelenik a **Frissítés elérhető: …** sáv, és a **Névjegy** lapfülön ott a legújabb verzió száma. A rendszer kb. félnaponta maga is megnézi.

### Mi van, ha frissítés közben megszakad a net?

A frissítés a csomag letöltésével kezdődik; ha az nem sikerül, a régi verzió változatlanul marad, és a gomb mellett az ok olvasható. A frissítés előtti mentés ilyenkor is megvan.

### Elvesznek az adatok frissítéskor?

Nem. A csere csak a bővítmény fájljait érinti, az adatbázis és a feltöltött fájlok megmaradnak.

### Kell valamit futtatnom az adatbázison frissítés után?

Nem. A rendszer az első megnyitott oldalnál magától hozzáigazítja az adatbázist.

### Ki kell kapcsolnom vagy törölnöm a régit frissítés előtt?

Nem kell, és ne is töröld. Egyszerűen töltsd fel az új zipet a régi fölé.

### Honnan tudom, hogy sikerült a frissítés?

A **Beállítások › Névjegy** lapfülön a **Plugin verzió** az új számot mutatja.

### Mi történik az adatokkal, ha kikapcsolom a bővítményt?

Semmi, megmaradnak. Visszakapcsolás után minden ott folytatódik.

### És ha törlöm a bővítményt?

A fájlok törlődnek, de az adattáblák és a beállítások az adatbázisban maradnak. Újratelepítés után a rendszer a régi adatokkal indul.

### Miért mutat rossz időt a rendszer?

Mert a WordPress időzónája nincs beállítva. A vezérlőpultban: **Beállítások › Általános › Időzóna**.

### Miért kell SMTP, ha a Levelezés már be van kötve?

Mert a kettő külön út. A **Levelezés** a postafiók saját szerverén küld, az értesítők viszont a WordPress levélküldőjén mennek. Ahhoz külön kell beállítani az SMTP-t.

### Működik helyi hálózaton, internet nélkül?

A felvitel, a munkalap, a pénztár és a helyi nyomtatvány igen. A levelezés, a számlázás és az asszisztens internetet kér.

### Kell más bővítmény a működéshez?

Maga a rendszer nem kíván másikat. Az értesítő levelekhez érdemes egy SMTP-bővítmény.

### Vissza tudok lépni az előző verzióra?

A rendszerben erre nincs gomb. Biztonságos visszalépés a frissítés előtti teljes szervermentésből lehetséges.
