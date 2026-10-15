---
kulcs: hibaelharitas
cim: Hibaelhárítás
csoport: Rendszer
modul:
szakaszok:
kulcsszavak: hiba, hibaüzenet, elakadtam, nem megy, nem működik, nem tölt be, nem ment, nem menthető, piros üzenet, nincs jogosultság, lejárt a munkamenet, nem megy ki az e-mail, nem jön levél, eltűnt, nem találom, lassú, 404, 403, korlát, ismert hiba, megoldás
osszefoglalo: A leggyakoribb elakadások tünet, ok és megoldás szerint, menüpontonként csoportosítva, a rendszer valódi hibaüzeneteivel.
---

## Mire való

Ez a fejezet akkor kell, amikor valami nem úgy megy, ahogy vártad. Menüpontonként sorolja fel a leggyakoribb elakadásokat, mindegyiknél három dologgal: **mit látsz** (a tünet vagy a hibaüzenet szó szerint), **miért** (az ok), és **mi a teendő**.

Hogyan használd:

1. Olvasd el a piros üzenetet. A rendszer hibaüzenetei megmondják, mi a baj, és legtöbbször azt is, mit kell tenned.
2. Keresd meg ebben a fejezetben a menüpontot, ahol elakadtál.
3. Ha nem találod, nyomd meg az [[F1]] billentyűt, és kérdezd meg az asszisztenst. Ha egy ablak hibát ad, magától is felajánlja a **Segíts** gombot.

Az első segítség szinte mindenre: töltsd újra az oldalt az [[F5]] billentyűvel. A rendszer adatot csak a mentés gombbal ír, ezért az újratöltés nem ront el semmit – legfeljebb a nyitott, el nem mentett ablak tartalma vész el.

## Belépés és oldalbetöltés

Elakadások a bejelentkezésnél és a műhely-felület megnyitásakor.

- **„Ehhez nincs jogosultságod."** – *Ok:* a fiókod be van jelentkezve, de nem fér hozzá a műhelyrendszerhez, vagy kollégaként egy csak adminisztrátornak szóló oldalt nyitottál meg. *Megoldás:* szólj az adminisztrátornak. A **Beállítások** menüpont a kollégának nem jár.
- **„Ez a fiók le van tiltva. Szólj az adminisztrátornak."** – *Ok:* a fiókodat letiltották. *Megoldás:* az adminisztrátor a **Csapat** menüpontban, a kolléga űrlapján állíthatja vissza **Aktív** állapotra.
- **„Nincs ilyen oldal" – „Ez a modul nem létezik."** – *Ok:* elírt vagy régi cím. *Megoldás:* válassz a bal oldali menüből, és frissítsd a könyvjelzőt.
- **A műhely címe a WordPress „nem található" oldalát adja.** – *Ok:* a WordPress címszabályai közül kiesett a műhelyé. A rendszer ezt magától pótolja. *Megoldás:* töltsd újra az oldalt. Ha így sem jó, a cím végére írd: `?muhely=attekintes` – ez mindig működik. Részletek: [Telepítés és frissítés](sugo:telepites).
- **Belépés után a WordPress vezérlőpultja nyílik meg, nem a műhely.** – *Ok:* adminisztrátor vagy; a kolléga kerül egyenesen a műhelybe. *Megoldás:* nyisd meg a műhely címét, vagy a vezérlőpult műhely-oldalán nyomd meg a **Megnyitás a műhely-felületen** gombot.
- **Az oldal magától újratöltődött.** – *Ok:* a rendszer közben új verziót kapott, és a nyitott oldal egyszer frissített. *Megoldás:* nincs teendő.

## Sebesség: ha lassú a rendszer

A rendszer sok százezer ügyféllel és munkalappal is gyors, ha a szerver (a gépen futó WordPress) jól van beállítva. Két beállításon múlik a legtöbb – ezeket a rendszergazda egyszer állítja be.

### Gyors betöltés (Beállítások › Rendszer › Sebesség)

Bekapcsolva a műhely-felület és a wp-admin is két fájlban tölti be a stílusokat és a szkripteket, sok tucat helyett. Hagyd bekapcsolva.

### Hol látod, hogy rendben van

**Beállítások › Rendszer › Sebesség**, a **Gyors betöltés** doboz alján: *Szerver: PHP OPcache* (be / ki) és *MySQL puffer* (megabájtban). Ezt a webszerver PHP-ja mondja meg, nem a parancssor.

### PHP OPcache bekapcsolása – LocalWP (Windows)

1. A LocalWP-ben állítsd le a webhelyet (**Stop site**).
2. Nyisd meg a webhely mappájában (**Go to site folder**) a `conf\php\php.ini.hbs` fájlt Jegyzettömbbel.
3. Keresd meg benne az `opcache` szót. Windowson van egy `; zend_extension = php_opcache.dll` sor (a `{{#if os.windows}}` részben): töröld az elejéről a pontosvesszőt.
4. Ugyanott (vagy a fájl végén) legyenek ezek a sorok – ha `opcache.enable=0` áll, írd át 1-re:
   `opcache.enable=1`, `opcache.memory_consumption=192`, `opcache.interned_strings_buffer=16`, `opcache.max_accelerated_files=20000`, `opcache.validate_timestamps=1`, `opcache.revalidate_freq=0` – soronként egy.
5. Mentsd, és indítsd el a webhelyet (**Start site**).
6. Ellenőrzés: Beállítások › Rendszer › Sebesség – *PHP OPcache: be*. Ha nem indul el a webhely, tedd vissza a pontosvesszőt (a fájlt a LocalWP a következő induláskor újra beolvassa).

### MySQL / MariaDB puffer – LocalWP

1. **Stop site**.
2. A webhely mappájában nyisd meg a `conf\mysql\my.cnf.hbs` fájlt.
3. A `[mysqld]` sor alá írd: `innodb_buffer_pool_size = 1G` (8 GB-nál kevesebb memóriájú gépen `512M`). Ha már van ilyen sor, azt írd át.
4. **Start site**, majd ellenőrizd: Beállítások › Rendszer › Sebesség – *MySQL puffer: 1024 MB*.

### Az ügyfélkereső frissítése után

Új verzióra lépéskor a rendszer egyszer, a háttérben feltölti az ügyfelek keresőadatait (kérésenként legfeljebb egy másodpercig, ott folytatja, ahol abbahagyta). Amíg ez tart, az ügyfélkereső a régi módon keres – ugyanazt találja, csak lassabban. Nagy állománynál ez néhány percnyi használat alatt ér véget.

### Mérés a saját gépen

A `tools/mero-meres.php` csak olvas: lefuttatja a gyakori lekérdezéseket a meglévő adatokon, és kiírja az időket, a MySQL beállításait és azt, hol olvas végig egy egész táblát. Indítás: **Open site shell**, a plugin mappájában `php tools/mero-meres.php`. Semmit nem ír az adatbázisba.

## Popupok és mentés

Bármelyik menüpont felugró ablakára (popupjára) és a mentésre érvényes elakadások.

- **„Az űrlap nem töltődött be. A szerver … hibakóddal válaszolt."** – *Ok:* lejárt a munkamenet (403), vagy a szerver hibát adott (500). *Megoldás:* töltsd újra az oldalt, szükség esetén lépj be újra. Ha ismétlődik, jelezd az adminisztrátornak a hibakóddal együtt.
- **„A beküldött adat túl nagy vagy lejárt a munkamenet. Kevesebb vagy kisebb fájllal próbáld, vagy frissítsd az oldalt."** – *Ok:* a csatolt fájlok együtt nagyobbak, mint amit a szerver egy beküldésben elfogad, vagy az oldal túl régóta áll nyitva. *Megoldás:* csatolj kevesebb vagy kisebb fájlt; ha nem csatoltál semmit, frissítsd az oldalt.
- **„A mentés nem sikerült. A szerver válasza nem értelmezhető."** – *Ok:* a szerver nem a várt választ adta (hiba vagy megszakadt kapcsolat). *Megoldás:* próbáld újra. Az ablak nyitva marad, a beírt adat megvan.
- **„Az adatbázis visszautasította a mentést."** – *Ok:* az adatbázis nem fogadta el a műveletet. Előfordulhat például frissítés után, amíg az adatbázis nem igazodott az új verzióhoz. *Megoldás:* töltsd újra az oldalt, és mentsd újra. Ha megmarad, az adminisztrátor nézze meg a **Beállítások › Névjegy** lapfülön a verziókat.
- **„Nincs ilyen rekord. Lehet, hogy időközben törölték."** – *Ok:* a megnyitott elem már nincs meg, vagy régi hivatkozásról jöttél. *Megoldás:* frissítsd a listát.
- **Bezárult az ablak, és elveszett, amit beírtam.** – *Ok:* a bezárás ([[Esc]], piros gomb, kattintás a sötét háttérre) nem kérdez rá a mentésre. *Megoldás:* mindig a mentés gombbal zárd le a munkát.
- **Az ablak apró betűs lett.** – *Ok:* a tartalom magasabb volt a képernyőnél, és a rendszer görgetés helyett lekicsinyítette. *Megoldás:* tedd teljes képernyőre a zöld ablakgombbal, vagy a **Beállítások › Logó és megjelenés › Betű területenként** dobozban vedd nagyobbra az „Űrlapok és popup ablakok" betűjét.
- **Az ablak furcsa méretben nyílik.** – *Ok:* a böngésző megjegyezte a legutóbbi méretet. *Megoldás:* kattints duplán az ablak szélére – visszaáll az alapméretre.

## Ügyfelek és eszközök

Az **Ügyfelek** és az **Eszközök** menüpont, illetve az ügyfél- és eszközűrlap üzenetei.

- **„A név kitöltése kötelező – e nélkül nem menthető az ügyfél."** – *Ok:* üres a név. *Megoldás:* írd be; cégnél a cég nevét, magánszemélynél a teljes nevet.
- **„Válassz ügyfelet a listából – gépelés önmagában nem elég."** – *Ok:* az ügyfélmezőbe beírtál egy nevet, de nem kattintottál a lenyíló találatra. *Megoldás:* kezdd el írni a nevet, és válaszd ki a listából, vagy nyomd meg az [[Enter]] billentyűt az első találathoz. Ha az ügyfél még nincs meg, a mező melletti **+** gombbal vedd fel.
- **„Válassz ügyfelet a listából – eszköz ügyfél nélkül nem vihető fel."** – *Ok:* az eszköz mindig egy ügyfélhez tartozik. *Megoldás:* előbb válaszd ki az ügyfelet.
- **„Az ügyfél mentve, de néhány fájl nem: …"** (eszköznél: **„Az eszköz mentve, de néhány fájl nem"**) – *Ok:* az adatok elmentődtek, de egy csatolt fájl túl nagy vagy nem engedélyezett típusú. *Megoldás:* az üzenet megnevezi a fájlt. Nyisd meg újra, és csatold kisebb méretben vagy más formátumban. Egy fájl legfeljebb 25 MB lehet, de a szerver korlátja ennél kisebb is lehet.
- **„A csatolt fájl nem fogadható el (túl nagy, nem engedélyezett típus vagy sérült fájl). Semmi nem mentődött."** – *Ok:* a fájl ellenőrzése már a mentés előtt megbukott. *Megoldás:* vedd ki a fájlt, vagy cseréld másikra, és mentsd újra.
- **„Ez a 15 számjegy nem ad ki érvényes IMEI-t – nézd meg, nem gépelted-e el."** – *Ok:* az IMEI ellenőrző számjegye nem stimmel. Ez figyelmeztetés, nem tiltás. *Megoldás:* olvasd le újra a készülékről.
- **„Előbb írd be a teljes, 15 számjegyű IMEI-t."** – *Ok:* a lekérdezéshez teljes IMEI kell. *Megoldás:* írd be mind a 15 számjegyet.
- **Az IMEI-ből nem töltődik ki a gyártó és a típus.** – *Ok:* a TAC-adatbázis nincs betöltve, vagy ez a készülék nincs benne. *Megoldás:* az adminisztrátor a **Beállítások › TAC adatbázis** lapfülön töltheti be; addig írd be kézzel.

## Munkalapok

A **Munkalapok** menüpont és az Áttekintés munkalap-táblázatának üzenetei.

- **„Válassz ügyfelet a munkalaphoz – ügyfél nélkül nem adható munkalapszám."** – *Ok:* számozott állapotú munkalaphoz ügyfél kell. *Megoldás:* válaszd ki az ügyfelet a listából.
- **„Válassz eszközt a munkalaphoz – a munkalap mindig egy eszközre vonatkozik."** – *Ok:* nincs kiválasztott eszköz. *Megoldás:* válaszd ki az ügyfél eszközét, vagy vedd fel a **+** gombbal.
- **„A kiválasztott eszköz nem ennek az ügyfélnek a készüléke."** – *Ok:* az ügyfelet átírtad, de az eszköz a régié maradt. *Megoldás:* válaszd ki újra az eszközt.
- **„A számozás épp foglalt – próbáld újra egy pillanat múlva."** – *Ok:* ugyanabban a pillanatban más is munkalapot mentett, és a rendszer egyszerre csak egy számot ad ki. *Megoldás:* nyomd meg újra a mentést.
- **„Nincs ilyen munkalap. Lehet, hogy időközben törölték."** – *Ok:* régi hivatkozás, vagy a lista elavult. *Megoldás:* frissítsd az oldalt.
- **„Ez az eszköz már nincs a munkalapon. Nyisd meg újra a lapot."** – *Ok:* közben valaki levette az eszközt a lapról. *Megoldás:* zárd be, és nyisd meg újra a munkalapot.
- **„Egy eszközös lapon a „Fizetve" a munkalapé – ott állítsd."** – *Ok:* az eszközönkénti fizetés csak több eszközös lapon él. *Megoldás:* a munkalap **Fizetve** jelölőjét használd.
- **„A munkalap egészben ki van fizetve. Előbb a lapon vedd le a „Fizetve" pipát."** – *Ok:* a teljesen kifizetett lapon az eszköz jelzője külön nem állítható. *Megoldás:* vedd le a lap **Fizetve** pipáját, aztán állítsd az eszközt.
- **„A gyakori hibák listája betelt (60 db). A Beállításokban törölhetsz belőle."** – *Ok:* a lista elérte a felső határt. Ugyanez igaz a gyakori megjegyzésekre. *Megoldás:* az adminisztrátor a **Beállítások › Gyakori hibák** lapfülön ritkítja.
- **„Legfeljebb 30 saját nézet menthető. Törölj egyet, mielőtt újat mentesz."** – *Ok:* az Áttekintés munkalap-táblázatának nézetei beteltek. *Megoldás:* törölj egy régit.
- **„Ennek a munkalapnak még nincs sorszáma, ezért címke sem nyomtatható."** – *Ok:* a lap még nem számozott állapotú. *Megoldás:* tedd számozott állapotba, mentsd el, utána nyomtass.

## Pénztár és fizetés

A **Pénztár** menüpont és a munkalap **Fizetés** ablakának üzenetei.

- **„Előbb mentsd el a munkalapot."** – *Ok:* a **Fizetés** ablak csak elmentett munkalapról nyílik. *Megoldás:* mentsd el a lapot, aztán fizess.
- **„Add meg a kapott összeget."** / **„Add meg legalább egy eszköz összegét."** – *Ok:* nincs beírt összeg. *Megoldás:* írd be, mennyit fizetett az ügyfél.
- **„Jelöld be, melyik eszközt fizetik."** – *Ok:* több eszközös lapon nem választottál eszközt. *Megoldás:* pipáld ki azt, amelyiket most kifizetik.
- **„Jövőbeli napra nem lehet tételt írni."** – *Ok:* a tétel dátuma későbbi a mainál. *Megoldás:* javítsd a napot.
- **„A kivétnél válaszd ki, ki vette ki a pénzt."** – *Ok:* a kivéthez személy kell. *Megoldás:* válassz a listából. A személyeket a **Beállítások › Házipénztár** lapfül tartja.
- **„Írj be összeget (KP, kártya vagy utalás)."** / **„Írd be, mi történt (leírás)."** – *Ok:* hiányzó mező a gyorsbevitelnél. *Megoldás:* töltsd ki, és nyomd meg újra az [[Enter]] billentyűt.
- **Nap végén nem stimmel a kassza.** – *Ok:* kimaradt egy tétel, vagy rossz fizetési módra került. *Megoldás:* a **Pénztár** menüpont **Ügynök** lapfülén kérdezd meg, hol az eltérés. Részletek: [Pénztár](sugo:penztar).
- **Excel-importnál: „Csak .xlsx vagy .csv fájl tölthető fel."** – *Ok:* régi `.xls` fájlt választottál. *Megoldás:* Excelben mentsd el `.xlsx` formátumban. Ha **„A fájl nem érkezett meg"** az üzenet, a fájl nagyobb, mint amit a szerver enged.

## Számlázás

A munkalapról nyíló számla ablak üzenetei.

- **„Nincs megadva a Számla Agent kulcs (Beállítások → Számlázás – Számlázz.hu)."** – *Ok:* nincs bekötve a Számlázz.hu. *Megoldás:* az adminisztrátor a **Beállítások › Számlázás** lapfülön adja meg a kulcsot, és a **Kapcsolat ellenőrzése** gombbal próbálja ki.
- **„A Számlázz.hu nem fogadta el a Számla Agent kulcsot."** (hibakód: 3) – *Ok:* hibás vagy visszavont kulcs. *Megoldás:* másold be újra a kulcsot a Számlázz.hu fiókodból.
- **„Számla csak sorszámot kapott (számozott állapotú) munkalaphoz készíthető."** – *Ok:* a munkalapnak még nincs száma. *Megoldás:* tedd számozott állapotba, és mentsd el.
- **„A vevő adatai hiányosak: …"** – *Ok:* a számlához kötelező vevőadat hiányzik; az üzenet felsorolja, melyik. *Megoldás:* töltsd ki a számla ablakában.
- **„Az adószám 11 számjegy, ebben az alakban: 12345678-1-12. Magánszemélynél hagyd üresen."** – *Ok:* hibás formátum. *Megoldás:* javítsd, vagy töröld a mezőt.
- **„Legalább egy tétel kell: pipálj ki egyet, vagy vegyél fel újat."** – *Ok:* egyetlen tétel sincs kijelölve. *Megoldás:* pipálj ki legalább egyet.
- **„Az „E-mail az ügyfélnek" be van pipálva, de nincs e-mail-cím."** – *Ok:* e-mailben kérted a számlát, cím nélkül. *Megoldás:* írd be a vevő e-mail-címét, vagy vedd ki a pipát.
- **„A Számlázz.hu nem adott vissza számlaszámot."** – *Ok:* a válasz megszakadt; a számla lehet, hogy elkészült. *Megoldás:* **előbb nézd meg a Számlázz.hu fiókodban**, elkészült-e, és csak utána próbáld újra – különben dupla számla lesz.
- **„Ez a számlaszám-előtag nincs felvéve a Számlázz.hu fiókodban."** (hibakód: 202) – *Megoldás:* vedd fel ott az előtagot, aztán próbáld újra. Az e-számlára vonatkozó üzenetnél (hibakód: 54) kapcsold ki az e-számla jelölőt a **Beállítások › Számlázás** lapfülön, vagy engedélyezd a Számlázz.hu-n.

## Napi árukiadás és rendelések

A **Napi árukiadás** és a **Rendelések** menüpont üzenetei.

- **„Nincs ilyen rendelés. Lehet, hogy időközben megváltozott – frissítsd az oldalt."** – *Ok:* más közben módosította. *Megoldás:* frissíts.
- **„Ehhez a rendeléshez nem küldhető értesítés (nincs ügyfele, vagy nem létezik)."** – *Ok:* a rendeléshez nincs ügyfél rendelve. *Megoldás:* nyisd meg a rendelést, és válassz ügyfelet.
- **„Érvénytelen e-mail cím: …"** – *Ok:* elgépelt cím. *Megoldás:* javítsd az ügyfél e-mail-címét.

## Üzenetek és ügyféloldal

Az **Üzenetek** menüpont és az ügyfélnek szóló ügyféloldal elakadásai.

- **„Írd be az üzenetet."** – *Ok:* üres üzenetet küldenél. *Megoldás:* írj szöveget.
- **Az ügyfél nem kapja meg az e-mail értesítést.** – *Ok:* nincs e-mail-címe, vagy a szerver levélküldése nem működik. *Megoldás:* ellenőrizd az ügyfél e-mail-címét; a levélküldésről lásd: [Telepítés és frissítés](sugo:telepites).
- **Az ügyfél azt mondja, nem tud belépni az ügyféloldalra.** – *Ok:* a telefonszám vagy a munkalap sorszáma nem egyezik; több hibás próbálkozás után az oldal 15 percig nem enged újra próbálkozni. *Megoldás:* egyeztesd vele a munkalapon szereplő telefonszámot és sorszámot. Részletek: [Üzenetek és ügyféloldal](sugo:uzenetek-rma).

## Levelezés

A **Levelezés** menüpont üzenetei.

- **„Nincs megnyitott e-mail-fiók. Állítsd be a Beállítások → Levelezés alatt, vagy nyisd meg a jelszavával."** – *Ok:* nincs bekötött fiók, vagy jelszóval zárva van. *Megoldás:* az adminisztrátor kösse be, vagy írd be a fiók jelszavát.
- **„A belépés nem sikerült: a szerver elutasította a felhasználónevet vagy a jelszót."** – *Ok:* hibás vagy visszavont alkalmazásjelszó. *Megoldás:* kérj újat a levelezőszolgáltatónál, és írd be a **Beállítások › Levelezés** lapfülön. A sima fiókjelszó nem jó, alkalmazásjelszó kell.
- **„A levelezőszerver nem érhető el (…)."** – *Ok:* nincs internet, rossz a szerver neve vagy a portja, vagy a tárhely tiltja a kimenő kapcsolatot. *Megoldás:* a **Kapcsolat ellenőrzése** gomb megmondja, a bejövő vagy a kimenő oldal hibás.
- **„A bejövő kapcsolat él (…), de a kimenő (SMTP) nem: …"** – *Ok:* olvasni tudsz, küldeni nem. *Megoldás:* ellenőrizd a kimenő szerver nevét, portját és titkosítását.
- **„Ez a postafiók jelszóval védett. Előbb nyisd meg a jelszavával."** – *Ok:* a fióknak külön jelszava van a rendszerben. *Megoldás:* írd be.
- **„Túl sok hibás próbálkozás. 15 perc múlva próbáld újra."** – *Ok:* ötször rossz jelszót írtál a postafiókhoz. *Megoldás:* várj 15 percet.
- **„Ez a levél már nincs meg ebben a mappában. Frissítsd a listát."** – *Ok:* a levelet közben máshol (telefonon, webes levelezőben) törölték vagy áthelyezték. *Megoldás:* frissítsd a listát.
- **„Véglegesen törölni csak a Kukából, a Spamből és a Piszkozatokból lehet."** – *Megoldás:* előbb tedd a kukába.
- **„Valamelyik e-mail-cím hibás. A címeket vesszővel válaszd el."** – *Megoldás:* javítsd a címzetteket; több cím közé vessző kell.
- **A levelezés költözés után nem megy, a jelszó „eltűnt".** – *Ok:* a szerver titkos kulcsai megváltoztak, a titkosított jelszó nem fejthető vissza. *Megoldás:* írd be újra az alkalmazásjelszót. Lásd: [Mentés, jogosultság, biztonság](sugo:mentes-biztonsag).

## Naptár

A **Naptár** menüpont üzenetei.

- **„Add meg az esemény megnevezését."** / **„Add meg a kezdés napját."** / **„Válaszd ki, kinek a naptárába kerüljön."** – *Ok:* kötelező mező hiányzik. *Megoldás:* töltsd ki.
- **„Ezt az eseményt csak a naptár gazdája vagy az adminisztrátor módosíthatja."** – *Ok:* más naptárában lévő eseményt írnál át. *Megoldás:* kérd meg a gazdáját.
- **„Ismétlődő esemény időpontját az űrlapján lehet módosítani."** – *Ok:* ismétlődő eseményt húzással nem lehet áttenni. *Megoldás:* nyisd meg, és ott írd át.
- **„A neveket az adminisztrátor szerkesztheti."** – *Megoldás:* a naptár neveit adminisztrátor veszi fel.
- **Nem jön az esemény e-mail értesítése.** – *Ok:* a szerver levélküldése vagy az időzített feladatai nem futnak. *Megoldás:* lásd: [Telepítés és frissítés](sugo:telepites).

## Csapat

A **Csapat** menüpont (kollégák, csoportok, belső üzenetek) üzenetei.

- **„Ez az e-mail-cím már egy másik fiókhoz tartozik."** / **„Ez a felhasználónév már foglalt."** – *Ok:* a kollégának már van fiókja. *Megoldás:* keresd meg a kollégák között – lehet, hogy le van tiltva.
- **„A felhasználónév legalább 3 karakter legyen (ékezet és szóköz nélkül)."** – *Megoldás:* adj hosszabb, ékezet nélküli nevet.
- **„A jelszó legalább 8 karakter legyen – vagy hagyd üresen, és a rendszer generál egyet."** – *Megoldás:* adj hosszabb jelszót, vagy hagyd üresen.
- **„Kollégát csak adminisztrátor vehet fel."** / **„Csoportot csak adminisztrátor kezel."** – *Megoldás:* kérd az adminisztrátort.
- **„Csak a saját üzenetedet vonhatod vissza."** – *Ok:* más üzenetét próbáltad visszavonni.

## Asszisztens

A jobb alsó sarokban élő asszisztens üzenetei.

- **„Az asszisztens ki van kapcsolva."** / **„Az asszisztenshez Claude API-kulcs kell."** – *Megoldás:* az adminisztrátor a **Beállítások › Asszisztens** lapfülön kapcsolja be, illetve adja meg a kulcsot.
- **„Az API-kulcs érvénytelen (401). Ellenőrizd az Asszisztens beállításaiban."** – *Ok:* hibás vagy visszavont kulcs. *Megoldás:* írj be újat, és nyomd meg az **API teszt** gombot.
- **„Az AI most túlterhelt – próbáld újra fél perc múlva."** – *Megoldás:* várj, és kérdezz újra.
- **„Nem érem el az AI-t: …"** – *Ok:* a szerver nem éri el az internetet. *Megoldás:* ellenőrizd a kapcsolatot.
- **„Mára elérted a napi keretet (… kérdés)."** – *Ok:* elfogyott a napi kérdéskeret. *Megoldás:* holnap újra megy; a keretet az adminisztrátor emelheti (**Napi kérdéskeret / kolléga**).
- **„A megerősítő kód nem egyezik. Írd be pontosan: …"** – *Megoldás:* a kártyán látható négy számjegyet írd be.
- **„Ez a javaslat lejárt – kérd újra az asszisztenstől."** – *Ok:* hat óránál régebbi javaslat.
- **„A rekord a művelet óta megváltozott – a visszaállítás azt is felülírná."** – *Ok:* a visszaállítandó adatot azóta más is módosította. *Megoldás:* nézd meg kézzel; ha biztos vagy benne, a **Mégis visszaállítom** gombbal megy.

## Nyomtatás, PDF és e-mail

A nyomtatványok (átvételi, munkalap, árajánlat, szállítólevél, pénztárzárás, listák) elakadásai. A részletek: [Nyomtatás, PDF és e-mail](sugo:nyomtatas).

- **Az előnézet üres vagy szürke.** – *Ok:* a böngésző nem jelenít meg PDF-et keretben (telefonon gyakori). *Megoldás:* az előnézet sarkában **Megnyitás új lapon**, vagy **Mentés PDF-be**.
- **A Nyomtatás gombra nem jön fel a nyomtatás-ablak.** – *Ok:* a böngésző nem enged a háttérből nyomtatni. *Megoldás:* az ablak néhány másodperc múlva hivatkozást ad (**Nyisd meg a PDF-et új lapon**); onnan nyomtass.
- **„Ezen a nyomtatványon belső megjegyzés vagy zárkód van, ezért az ügyfél címére nem küldhető.”** – *Ok:* belső papírt próbáltál az ügyfélnek küldeni. *Megoldás:* a **Munkalapot** vagy az **Átvételi elismervényt** küldd.
- **Kétszer annyi példány jött ki.** – *Ok:* a **Példányszám** mezőben és a böngésző nyomtatás-ablakában is több példány volt. *Megoldás:* a böngészőben maradjon 1.
- **„A levél nem ment el: …”** – *Ok:* a szerver levélküldője nincs beállítva, vagy a kiválasztott postafiók nem érhető el. *Megoldás:* kösd be a postafiókot a **Beállítások › Levelezés** lapfülön, és azt válaszd **Feladónak**.
- **„A próbanyomat nem küldhető el…”** – *Ok:* a próbanyomat kitalált adatokkal készül. *Megoldás:* valódi lapról küldj.
- **Nincs Nyomtatás gomb a munkalapon.** – *Ok:* a lap még nincs elmentve. *Megoldás:* mentsd el; utána a rendszer felajánlja az átvételi elismervényt.
- **Nem jelenik meg a felajánlás új lapnál vagy lezáráskor.** – *Ok:* ki van kapcsolva. *Megoldás:* **Beállítások › Nyomtatványok**, **Felajánlás és e-mail** doboz.
- **„A mentett kulcs ezen a telepítésen nem olvasható…”** (Számlázás, IMEI-lekérdezés) – *Ok:* a rendszer másik gépre költözött, vagy megváltoztak a `wp-config.php` titkos kulcsai. *Megoldás:* írd be újra a kulcsot.

## Beállítások, mentés, frissítés

A **Beállítások** menüpont, a teljes mentés és a frissítés elakadásai – ezek az adminisztrátort érintik.

- **„Lejárt a munkamenet – töltsd újra az oldalt, és próbáld újra."** – *Ok:* a Beállítások oldal túl régóta állt nyitva. *Megoldás:* [[F5]], majd mentés újra.
- **„A fájl nem érkezett meg (túl nagy, vagy megszakadt a feltöltés)."** – *Ok:* a logó vagy a háttér nagyobb a szerver feltöltési korlátjánál. *Megoldás:* tölts fel kisebb fájlt.
- **„A szerveren nincs gzip – a mentés nem készülhet el."** / **„A mentési mappa nem írható: …"** – *Megoldás:* a tárhely üzemeltetője kapcsolja be a tömörítést, illetve adjon írási jogot a feltöltési mappára.
- **A TAC-betöltésnél: „A data/tac.csv.gz nem olvasható."** – *Ok:* a bővítmény hiányosan került fel. *Megoldás:* töltsd fel újra a teljes zipet.
- **Frissítés után furcsán néz ki a felület.** – *Ok:* a böngésző még a régi stíluslapot használja. *Megoldás:* [[Ctrl]]+[[F5]]. A rendszer egyébként magától is cseréli.

## Ismert korlátok

Ezek nem hibák, hanem a rendszer mostani működésének határai:

- **Nagyított betűméretnél a popup visszakicsinyíthet.** Ha a **Betűméret** csúszkát feljebb húztad, egy magas ablak (például egy több eszközös munkalap) már nem fér ki, ezért a rendszer lekicsinyíti – így a betű végül nem lesz nagyobb. Segít a teljes képernyő, vagy ha csak az ablakok betűjét növeled a **Betű területenként** dobozban, a listákét pedig nem.
- **Kifizetett lapra felvett új eszköz „fizetve" lesz.** Ha egy már teljesen kifizetett munkalapra utólag új eszközt veszel fel, az is kifizetettként jelenik meg. Vedd le a lap **Fizetve** pipáját, és állítsd be eszközönként.
- **A Számla ablak fejléce eladásnál is „Munkalap".** Napi árukiadásról vagy rendelésről nyitva is „Munkalap" és a sorszám áll a címben. Csak a felirat ilyen, a számla jó.
- **A popup bezárása nem kérdez rá a mentésre.**
- **A fejléc keresője munkalapszámra nem keres** – csak ügyfélre és eszközre.
- **A rács keresője nagyon sok munkalapnál lassabb, ha a keresett szöveg ritka.** A „tartalmazza" keresés minden látható oszlopot végignéz; ha sok a találat, az első oldal hamar megvan, ha alig van (vagy nincs), a teljes állományt végig kell olvasni. Szűkíts oszlopszűrővel, vagy rejtsd el a fölösleges oszlopokat.
- **Háttérben (másik fülön) a jelvények nem frissülnek.** A közös háttérkérés rejtett lapon szünetel; visszatéréskor azonnal frissül minden. A levelek letöltése (Levelezés) ettől függetlenül a saját ütemében fut.
- **A mentésből nincs visszatöltő felület.** (Napi mentés magától készül.) Lásd: [Mentés, jogosultság, biztonság](sugo:mentes-biztonsag).
- **A másoló gomb nem biztonságos (`http://`) címen egyes böngészőkben nem működik.** Ilyenkor ezt írja: *Nem sikerült kimásolni – jelöld ki, és Ctrl+C*.

## GYIK

### Nem tölt be az oldal. Mit csináljak?

Töltsd újra az [[F5]] billentyűvel. Ha a bejelentkező oldal jön, lépj be újra. Ha a „nem található" oldal jön, ellenőrizd a címet, vagy írd a végére: `?muhely=attekintes`.

### Eltűnt egy menüpont. Hová lett?

A **Beállítások** csak adminisztrátornak látszik. A **Csapat** közös fiókos módban az üzenőfal nevén szerepel. Ha a menü össze van csukva, csak az ikonok látszanak: nyisd ki a fejléc háromvonalas gombjával.

### Eltűntek a csempék az Áttekintésről. Miért?

Vagy elrejtetted őket a **Csempék elrejtése** gombbal (ugyanott **Csempék megjelenítése**), vagy az adminisztrátor kikapcsolta a csempét a **Beállítások › Áttekintés csempéi** lapfülön.

### Nem megy ki az e-mail. Hol keressem a hibát?

Attól függ, melyik. A **Levelezés** menüpont levelei a bekötött postafiókon mennek: ott a **Kapcsolat ellenőrzése** gomb segít. Az ügyfél-értesítők és a naptár levelei a szerver levélküldőjén mennek: ahhoz a WordPressben kell beállított levélküldés. Lásd: [Telepítés és frissítés](sugo:telepites).

### Miért kapok „Nincs jogosultságod ehhez." üzenetet?

A fiókod nem jogosult a műveletre – például közben kijelentkeztél egy másik lapon, vagy letiltották. Töltsd újra az oldalt, és lépj be újra. Ha megmarad, szólj az adminisztrátornak.

### Kidobott a rendszer munka közben. Miért?

Lejárt a bejelentkezésed, vagy a fiókodat letiltották. Lépj be újra. Amit nem mentettél el, azt újra be kell írnod.

### Miért nem tudom elmenteni a munkalapot?

Olvasd el a piros üzenetet az ablak tetején. Leggyakrabban az ügyfél vagy az eszköz nincs a listából kiválasztva – a begépelt név önmagában nem elég.

### Beírtam az ügyfél nevét, mégis azt mondja, válasszak ügyfelet. Miért?

Mert a nevet ki kell választani a lenyíló listából. Így biztos, hogy a munkalap a jó ügyfélhez kerül, nem egy hasonló nevűhöz.

### Miért nem találja a kereső a munkalapszámot?

A fejléc keresője ügyfelet és eszközt keres. Munkalapszámra az Áttekintés táblázatában vagy a **Munkalapok** menüpontban keress.

### Miért nem találom az ügyfelet, pedig biztosan felvettük?

Lehet, hogy inaktív. Az **Ügyfelek** listában nyomd meg az **Inaktívak** gombot. A fejléc keresője csak aktív ügyfelet mutat.

### Kétszer mentettem, most két munkalap van. Mit tegyek?

Munkalapot törölni nem lehet. A fölöslegeset tedd érvénytelen állapotba; a sorszáma és a története megmarad.

### Rossz ügyfélhez vettem fel az eszközt. Át lehet tenni?

Nyisd meg az eszközt, és válassz másik ügyfelet a listából, majd mentsd el.

### Nem lehet kimásolni az adatot a másoló gombbal. Miért?

A böngésző nem engedte. Jelöld ki az adatot egérrel, és nyomd meg a [[Ctrl]]+[[C]] billentyűket. Tartós megoldás, ha a rendszer `https://` címen fut.

### Túl kicsi vagy túl nagy minden. Hogyan állítom vissza?

A profilmenüben a **Betűméret** csúszka melletti körkörös nyíl visszaáll a cég alapméretére. Nézd meg a böngésző nagyítását is: a [[Ctrl]]+[[0]] állítja alapra.

### Rossz időt mutat az óra. Hol állítom át?

Az óra a WordPress időzónája szerint jár. Az adminisztrátor a WordPress általános beállításaiban állítja be. Lásd: [Telepítés és frissítés](sugo:telepites).

### Miért nem látszik a „zárásig hátralévő idő"?

Mert nincs megadva nyitvatartás. Az adminisztrátor a **Beállítások › Nyitvatartás és óra** lapfülön adja meg.

### Nem válaszol az asszisztens. Mi a baj?

Ki van kapcsolva, nincs megadva API-kulcs, nincs internet, vagy elfogyott a napi kérdéskeret. Az üzenete megmondja, melyik.

### Az asszisztens azt mondja, megcsinálta, de nem változott semmi. Miért?

Mert a módosítás csak javaslat, amíg a kártyán meg nem nyomod az **Engedélyezem** gombot.

### Nem készül el a számla. Hol kezdjem?

Olvasd el az üzenetet: megmondja, mi hiányzik. Ha a Számlázz.hu hibakódot ad, az üzenet végén ott a szám. Hálózati hiba után először mindig a Számlázz.hu fiókodban nézd meg, elkészült-e a számla.

### Lassú a rendszer. Mit tehetek?

Zárd be a fölösleges böngészőlapokat, és töltsd újra az oldalt. Ha mindenkinél lassú, az a szerver vagy a hálózat gondja – jelezd az üzemeltetőnek.

### Nem tudok fájlt csatolni. Miért?

A fájl túl nagy, vagy a típusa nem engedélyezett. Az üzenet megnevezi a fájlt és az okot.

### Frissítés után hibát kapok mentéskor. Mi a teendő?

Töltsd újra az oldalt: az adatbázis az első megnyitott oldalnál igazodik az új verzióhoz. Ha megmarad, az adminisztrátor ellenőrizze a **Névjegy** lapfülön a verziókat.

### Kinek szóljak, ha semmi nem segít?

Az adminisztrátornak. Írd le, melyik menüpontban voltál, mit csináltál, és másold ki a hibaüzenetet szó szerint. A **Beállítások › Névjegy** lapfül verzióit is add meg.
