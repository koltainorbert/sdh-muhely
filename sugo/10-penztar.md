---
kulcs: penztar
cim: Házipénztár
csoport: Pénz
modul: penztar
szakaszok: penztar, fizmodok
kulcsszavak: pénztár, házipénztár, kassza, napi kassza, zárás, napi zárás, nyitó, készpénz, KP, bankkártya, utalás, bevétel, kivét, kifizetés, befizetés, váltópénz, címlet, pénzszámolás, eltérés, hiány, többlet, újranyitás, napló, riport, statisztika, export, CSV, Excel, import, ügynök, egyeztetés, fizetés, előleg, részfizetés, fizetési mód, tisztelgés, tiszteleg, vigyázz, bokacsattintás, pénz a kasszában, figura tiszteleg
osszefoglalo: A napi kassza – bevételek, kivétek, kifizetések, címletes számolás és zárás, riport, statisztika és egyeztető ügynök.
---

## Mire való

A **Házipénztár** a napi kassza nyilvántartása. Azt mutatja, mennyi készpénznek kell a fiókban lennie, és mi történt a pénzzel a nap folyamán.

Egy tétel egy pénzmozgás. Ötféle lehet:

| Típus | Mit jelent | Hatása a kasszára |
|---|---|---|
| **Bevétel** | Az ügyfél fizetett: készpénzzel, bankkártyával vagy utalással. | A készpénz növeli. |
| **Kivét** | Valaki pénzt vett ki a kasszából. Mindig névvel. | Csökkenti. |
| **Kifizetés** | A kasszából fizettél valakinek (beszállító, futár, posta). | Csökkenti. |
| **Befizetés** | Pénz került a kasszába, ami nem bevétel (például váltópénz). | Növeli. |
| **Nem került kasszába** | Jelölés: a fizetés megtörtént, de kérésre nem lett pénztártétel. | Nincs. |

A kassza egyenlegét csak a készpénz adja. A bankkártyás és az utalásos fizetés a forgalomban látszik, a fiókban lévő pénzt nem érinti.

Minden nap magától megnyílik. A nyitó összeg az előző nap záró készpénze: ha az előző napot megszámoltad, a megszámolt összeg, különben a várható.

## Hol találod

- Az oldalmenüben: [Pénztár](modul:penztar). Az oldal címe **Házipénztár**.
- Az Áttekintésen a **Pénztár** csempe a kasszában most várható készpénzt mutatja.
- A munkalap, a napi árukiadás és a rendelés ablakának alján a **Fizetés** gomb a pénztárba is beírja a fizetést.

Az oldal tetején a mai dátum, a nap állapota (**Nyitott** vagy **Lezárva**) és a kasszában várható készpénz áll. Alatta hat lapfül: **Ma**, **Napló**, **KP – kassza**, **Riport**, **Statisztika**, **Ügynök**. Keresésnél megjelenik egy hetedik is: **Keresés**. A rendszer megjegyzi, melyik lapfülön jártál utoljára.

## Lépésről lépésre: bevétel, kivét, kifizetés rögzítése

**Gyorsan, a Ma lapfülön**

1. A **Ma** lapfül tetején a gyorssorba írd be, mi történt.
2. Töltsd ki a **KP** vagy a **Kártya** mezőt. A **Név**, a **Munkalap** és a **Számlaszám** nem kötelező.
3. Nyomd meg az [[Enter]] billentyűt, vagy kattints a **Rögzítés** gombra.

Ha beírod a munkalap sorszámát, a rendszer a munkalapról kitölti az üres mezőket: a nevet, a leírást és az összeget. Ha ugyanarra a munkalapra már van pénztártétel, rákérdez; az **Igen, beírom** gombbal mégis rögzítheted.

**Részletesen, ablakban**

1. A fejlécben kattints a **Bevétel**, a **Kivét** vagy a **Kifizetés** gombra.
2. Az ablak tetején a típus még átváltható. Itt választható a **Befizetés** is.
3. Töltsd ki a típushoz tartozó mezőket (lásd lent), majd kattints a **Rögzítés** gombra.

| Típus | Kötelező | További mezők |
|---|---|---|
| **Bevétel** | **Mi történt**, **Összeg** | **Ügyfél neve**, **Fizetési mód**, **Munkalap sorszám**, **Számlaszám**, **Vonalkód / IMEI** |
| **Kivét** | **Ki vette ki**, **Összeg** | **Megjegyzés** |
| **Kifizetés** | **Mi történt** vagy **Kinek**, **Összeg** | **Számlaszám** |
| **Befizetés** | **Mi történt**, **Összeg** | **Megjegyzés** |

Bevételnél a **Fizetési mód** lehet **Készpénz**, **Bankkártya**, **Utalás** vagy **Vegyes**. Vegyesnél három külön összegmezőt kapsz.

> **Példa:** A futárnak 3 000 Ft-ot fizetsz a kasszából. Kattints a **Kifizetés** gombra, a **Kinek** mezőbe írd be: „Példa Futár", az **Összeg** mezőbe: `3000`. A gyakran fizetett partnerek a mező alatt gombként megjelennek.

## Lépésről lépésre: fizetés rögzítése munkalapról

A legtöbb bevétel nem a pénztár oldalán, hanem a munkalapon keletkezik.

1. Nyisd meg a munkalapot (vagy az árukiadást, rendelést), és az ablak alján kattints a **Fizetés** gombra. A rendszer előbb elmenti a lapot.
2. A **Fizetés** ablak tetején látod a **Munkalap értéke**, az **Előleg**, a **Pénztárban eddig** és a **Fizetendő** összeget.
3. Válaszd ki, mi történik:
  - **Teljes fizetés – lezárom:** az ügyfél mindent kifizet. A lap **Fizetve** lesz.
  - **Előleg / részfizetés:** csak egy részt fizet. A lap nyitva marad.
4. Válaszd ki a **Fizetési mód** értékét: **Készpénz**, **Bankkártya**, **Utalás** vagy **Vegyes** (készpénz és kártya együtt).
5. Ellenőrizd a **Kapott összeg** mezőt. Teljes fizetésnél a rendszer a hátralékot ajánlja fel.
6. Hagyd bepipálva a **Kerüljön be a házipénztárba** sort, majd kattints a **Fizetés rögzítése** gombra.

A fizetés a mai napra kerül a pénztárba, a munkalap sorszámával. Előlegnél a leírás elé **Előleg –** kerül. A számlaszámot nem kell beírnod: a számla kiállítása után magától bekerül a pénztártételbe.

**Több eszköz egy munkalapon:** a Fizetés ablakban minden eszköz külön sor, pipával. Minden kifizetett eszköz külön pénztártétel lesz. Ha csak az egyiket fizetik ki, vedd ki a pipát a többinél: az az eszköz **Fizetve** lesz, a munkalap pedig nyitva marad.

> **Fontos:** Ha kiveszed a **Kerüljön be a házipénztárba** pipát, a munkalap fizetése rögzül, de a kassza nem változik. A naplóba ilyenkor egy **Nem került kasszába** jelölés kerül az összeggel, hogy az egyeztetésnél látszódjon: ez szándékos volt.

Ha a munkalapon kézzel pipálod be a **Fizetve** jelölőt, vagy megnöveled az előleget, mentés után a rendszer megkérdezi: **Bekerüljön a házipénztárba?** Három válasz van: **Beírás a pénztárba**, **Nem kell** (nyoma marad), **Később**.

Az ablak mindig csak azt ajánlja, ami **még hiányzik** a pénztárból. Ha az előleg egy része korábban már bekerült (vagy **Nem kell**-lel jelölted), az ablak tetején kiírja: *Előleg a lapon: 20 000 Ft · már a pénztárban: 8 000 Ft · hiányzik: 12 000 Ft*. Több eszköznél az eszköz neve alatt is látszik, mennyi járna rá és mennyi van már bent. Hogy mi került be korábban, azt a munkalap **Pénztárbizonylatok** lapfülén látod.

## Lépésről lépésre: a kassza megszámolása és a nap zárása

1. Nyisd meg a **KP – kassza** lapfület. (A **Ma** lapfül alján a **Kassza megszámolása és zárás** hivatkozás is ide visz.)
2. Írd be címletenként, hány darab van a fiókban. A [[Tab]] vagy az [[Enter]] a következő címletre lép, a fel és le nyíl eggyel növeli vagy csökkenti a darabszámot.
3. Jobb oldalon azonnal látod a **Megszámolt** összeget és alatta a levezetést:
  - **Nyitó**
  - **+ KP bevétel**
  - **+ Befizetés** (ha volt)
  - **− Kifizetés**
  - **− Kivét**
  - **Várható a kasszában**
4. A rendszer kiírja az eredményt: **Egyezik**, **Többlet** vagy **Hiány**, az összeggel.
5. Ha van mit hozzáfűznöd, írd be a **Megjegyzés a zárához** mezőbe.
6. Kattints a **Nap zárása** gombra.

Ha még nem akarsz zárni, a **Csak számolás mentése** gomb elmenti a darabszámokat, a nap nyitva marad.

**Zárás után az asszisztens szól.** Amint a nap lezárult, az asszisztens figurája figyelmeztet a zárás utáni teendőre – alapból arra, hogy a **bankkártya-terminált is le kell zárni** (napi zárás a terminálon). A buborék nem tűnik el magától: a **Megvan ✓** gombbal igazolod vissza. Ugyanitt a **Zárás nyomtatása** gomb megnyitja a nap zárólapját. Ha az asszisztens ki van kapcsolva, ugyanez felugró ablakban jelenik meg. A szöveg és a kapcsoló a **Beállítások › Házipénztár** lapfülön van.

**A zárás kinyomtatható.** A **Zárás nyomtatása** gomb megnyitja a **Pénztárzárás** nyomtatványt: összesítő, megszámolt készpénz, eltérés, címletek, a nap tételei, aláírás. Nyomtatható, PDF-be menthető, e-mailben küldhető – lásd: [Nyomtatás, PDF és e-mail](sugo:nyomtatas).

Ha a zárás eltéréssel ment, az egyeztető ügynök rögtön megnézi a napot, és a gombok alatt megjelenik, mit talált. Eltérésnél az **Ügynök: miért?** hivatkozással zárás előtt is megkérdezheted.

> **Tipp:** A lapfül tetején a nyilakkal vagy a dátummezővel korábbi napot is megnyithatsz. Ha egy múltbeli nap lezáratlan maradt, az oldal tetején figyelmeztető sáv jelzi, a **Megszámolom és lezárom** gombbal.

## Lépésről lépésre: lezárt nap javítása és újranyitása

A lezárt nap nem megváltoztathatatlan, de minden módosítás nyomot hagy.

- **Tétel javítása lezárt napon:** kattints a tétel sorára, javítsd, és mentsd. Az ablak figyelmeztet, hogy a nap már le van zárva. A nap várható összege és eltérése újraszámolódik.
- **Új tétel múltbeli napra:** a **Napló** lapfülön a nap fejlécében kattints a **+ tétel erre a napra** hivatkozásra, vagy az új tétel ablakában állítsd át a **Nap** mezőt. Jövőbeli napra nem írható tétel.
- **Újraszámolás:** lezárt napon a **KP – kassza** lapfülön a gomb felirata **Újraszámolás mentése**.
- **Újranyitás:** a **Ma** lapfül lezárást jelző sávjában vagy a **KP – kassza** lapfülön kattints az **Újranyitás** gombra, majd a megerősítésre (**Biztosan újranyitod?**).

Ha egy korábbi nap összege megváltozik, az utána következő napok nyitó összege is magától igazodik.

Hogy a mai napon ki mit rögzített, módosított, törölt, zárt vagy nyitott újra, azt a **Ma** lapfül alján a **Változásnapló** mutatja meg.

## Lépésről lépésre: tétel módosítása és törlése

1. Kattints a tétel sorára a **Ma**, a **Napló** vagy a **Keresés** lapfülön.
2. A **Pénztártétel módosítása** ablakban javítsd, amit kell, és kattints a **Mentés** gombra.
3. Törléshez kattints a **Törlés** gombra, majd a megerősítésre (**Biztosan törlöd?**).

A törölt tétel nem vész el: a kassza összegéből kikerül, de a változásnaplóban megmarad. A **Keresés** lapfülön a **Törölt tételek** szűrővel előkereshető, az ügynök pedig a nap egyeztetésénél felajánlja a **Visszaállítás** gombot.

A sorok elején álló pipa nem törlés: a kipipált sor áthúzva látszik. Arra való, hogy egyeztetéskor megjelöld, amit már megnéztél. Az összegeket nem érinti. A fejléc pipája a tábla minden sorát váltja.

## Lépésről lépésre: napló, keresés, riport és export

**Napló.** A napok időrendben, a legújabb elöl, oldalanként hét nap. Minden napnál látod az állapotot, az eltérést, a nyitó összeget és a tételeket. Szűrhető: **Minden nap**, **Csak eltérés**, **Lezáratlan**. Az **Ugrás napra** mezővel egy adott napra lépsz.

**Keresés.** A fejléc keresőmezőjébe írhatsz számlaszámot, munkalap sorszámot, nevet vagy összeget. A **Keresés** lapfülön tovább szűkíthetsz: időszak (**Tól**, **Ig**), **Összeg (min)** és **Összeg (max)**, típus, fizetési mód, **Számla nélkül**, **Munkalap nélkül**, **Törölt tételek**. Ha munkalapszámra keresel, a találatok fölött kártya mutatja a munkalap értékét és azt, mennyi van belőle a pénztárban. Ha fizetve van, de hiányzik a pénz, a **Beírás a pénztárba** gomb egy kattintással pótolja.

**Riport.** Válassz időszakot a gombsorból (**Ma**, **Tegnap**, **Ez a hét**, **Előző hét**, **Ez a hónap**, **Előző hónap**, **Negyedév**, **Idén**, **Tavaly**, **Utolsó 12 hónap**, **Mind**, **Egyéni**). A riport mutatja a forgalmat, a készpénzes és kártyás bevételt, a kivéteket személyenként, a kifizetéseket partnerenként, a számla és munkalap nélküli tételek számát, valamint a napokat egyenként. Egy nap sorára kattintva a naplóban arra a napra ugrasz. A **Nyomtatás / PDF** gomb megnyitja az időszak **Pénztárjelentés** nyomtatványát (nyomtatás, mentés PDF-be, küldés e-mailben).

**Statisztika.** Ugyanazokkal az időszakokkal dolgozik, és a bontás is választható: **Automatikus**, **Napi**, **Heti**, **Havi**, **Éves**. Látod a forgalom alakulását, a hét napjainak átlagát, a bevételt kategóriánként, a legnagyobb tételeket és eltéréseket, több évnél az évek összevetését. Az óránkénti forgalom csak a rendszerben rögzített tételekből számol, mert az importált sorok időpontja nem ismert.

**Export.** A **Riport** lapfülön az **Excel (CSV) letöltés**, a **Napló** lapfülön az **Excel (CSV)** gomb tölti le a tételeket. A fájl Excelben megnyitható, napi blokkokban, naponként **Napi összesen** sorral. A törölt tételek nincsenek benne.

## Lépésről lépésre: az egyeztető ügynök – miért hiányzik vagy több a pénz

Az **Ügynök** lapfül összeveti a pénztárat a munkalapokkal és a számlákkal. Csak javasol: minden javítást te hagysz jóvá egy gombbal.

1. Nyisd meg az **Ügynök** lapfület. A mai nap egyeztetése magától lefut.
2. Más naphoz állítsd be a dátumot a **Nap egyeztetése** mezőben, és kattints az **Egyeztetés** gombra.
3. Olvasd el az összegzést. Ha a kassza nem egyezik, megmondja, mennyivel van több vagy kevesebb, mint a várható.
4. Ha az ügynök talált olyan tételeket, amelyek együtt pontosan kiadják az eltérést, azt a **Pontos magyarázat** részben írja le.
5. A megállapításoknál a gombbal azonnal javíthatsz.

| Megállapítás | Mit ajánl |
|---|---|
| **Fizetve, de nincs a pénztárban** | **Beírás a pénztárba**, **Munkalap megnyitása** |
| **Számla kiállítva, de nincs a pénztárban** | **Beírás a pénztárba** |
| **KP-ként rögzítve, de kártyás** (és fordítva) | **Átírás kártyára** / **Átírás készpénzre** |
| **Kétszer rögzítve?** | **Megnyitás (törlés)** |
| **Többet rögzítettek, mint a munkalap értéke** | **Tételei megkeresése** |
| **Számlaszám pótolható** | **Számlaszám beírása** |
| **Kivét név nélkül** | a jogosultak neve gombként |
| **Törölt tétel** | **Visszaállítás** |
| **Lehetséges elgépelés** | **Tétel megnyitása** |
| **Az előző nap nem volt megszámolva** | **A nap egyeztetése** |
| **Zárás után módosítva** | **Változásnapló** |

Jobb oldalon a **Nyitott ügyek** az elmúlt három hét elmaradásait gyűjti össze: fizetett munkalap, amely nincs a pénztárban; pótolható számlaszámok (**Mind beírása**); eltéréssel zárt napok; lezáratlan napok.

A kérdésmezőbe szabadon is írhatsz, például egy munkalap- vagy számlaszámot, vagy azt, hogy miért hiányzik egy összeg. A válasz fölött látszik a forrása: **Helyi keresés**, vagy – ha a rendszerben be van állítva AI-kulcs – **Claude AI**. Az ügynök kérdésre sem módosít semmit.

## Lépésről lépésre: a régi Excel „Zárás" tábla importja és visszavonása

Ha eddig Excel-táblában vezetted a napi zárást, a múltat betöltheted.

1. A fejlécben kattints az **Import** gombra.
2. Kattints a **Fájl kiválasztása** gombra, vagy húzd a fájlt az ablakba. `.xlsx` és `.csv` fájl tölthető fel. A régi `.xls` fájlt előbb Excelben mentsd el `.xlsx` formátumban.
3. A rendszer beolvassa a fájlt, és **előnézetet** mutat. Ekkor még semmi nem kerül az adatbázisba. Látod: hány tételsor és hány nap van benne, mennyi az **Új** (ami már bent van, kimarad), az időszakot, a készpénzes és kártyás bevételt, a kivéteket személyenként, a kifizetéseket és a **Kezdő egyenleg** összegét.
4. Nézd meg a figyelmeztetéseket és a **Minta** részt (az első és az utolsó öt sor).
5. Kattints az **Importálás** gombra. A beírás adagokban halad, folyamatjelzővel.
6. A végén a rendszer elkészíti a napok összesítőit és a záró egyenlegeket. Kattints a **Bezárás** gombra.

Amit a beolvasó tud a régi tábláról:

- A táblában a **Dátum** fejlécnek az A oszlopban kell állnia. A fejléc- és az összesítő sorokat átugorja.
- Az összesítő sor **KP a kasszában** összege lesz az adott nap záró, megszámolt összege.
- A negatív kifizetésből kivét lesz, ha a szöveg kivétet említ, és a névből felismeri a személyt; különben kifizetés. A pozitív összegből befizetés.
- A hibás dátumú sor az előző sor dátumát kapja. Erről figyelmeztetést látsz.
- A tábla kezdő egyenlege az első importált napra, külön nyitó tételként kerül be.
- Az importált múltbeli napok lezárt állapotúak lesznek. Amit a rendszerben már megszámoltál vagy lezártál, azt az import nem írja felül.

Ha a beírás megszakad, a hibaüzenet mellett megjelenik a **Folytatás innen** gomb. A már beírt sorok nem kerülnek be kétszer. Ugyanez igaz, ha később a kibővült táblát töltöd fel újra: csak az új sorok kerülnek be.

**Visszavonás:** az **Import** ablak alján kattints a **Korábbi import visszavonása…** hivatkozásra, majd a megerősítésre. Az összes importált sor törlődik; a rendszerben kézzel vagy munkalapról rögzített tételek megmaradnak. Újbóli importnál az előnézetben a korábban importált sorok cseréjét kérő pipával egy lépésben is lecserélheted a régit.

## A Beállítások „Házipénztár" lapfüle

A Beállítások menüpont **Pénz és eladás** csoportjában találod a **Házipénztár** lapfület. A Beállításokat csak adminisztrátor nyithatja meg.

- **Kivétre jogosultak** – soronként egy személy, ebben az alakban: `Teljes név | becenév, becenév`. Például: `Minta Márta | Márti, Márta`. A pénzkivétnél csak közülük lehet választani. A becenevekből ismeri fel a rendszer az importált sorokban, ki vette ki a pénzt. Ha a lista üres, a kivétnél a nevet kézzel kell beírni.
- **Címletek** – a kassza megszámolásához használt címletek forintban, vesszővel elválasztva. Üresen hagyva a szokásos címletek jönnek vissza.
- **Készpénzes fizetési módok** – a munkalap mely fizetési módjai számítanak készpénznek.
- **Kártyás fizetési módok** – melyek számítanak kártyásnak. A munkalap minden más fizetési módja az utalás oszlopba kerül, és nem érinti a kasszát.
- **Statisztikai kategóriák** – soronként `Kategória: kulcsszó, kulcsszó`. A statisztika a bevétel leírásában keresi a kulcsszavakat; az első egyező sor nyer. Üresen hagyva az alap lista jön vissza.
- **Munkalap fizetésekor ajánlja fel a pénztárba írást** – ha be van pipálva, a kézzel fizetettre állított munkalap és a számla után megjelenik a **Bekerüljön a házipénztárba?** kérdés.
- **Záráskor mindig figyelmeztessen** és **Az emlékeztető szövege** (az **Emlékeztető a nap zárásakor** dobozban) – a nap lezárása után az asszisztens ezt a szöveget mondja el. Üresen az alap szöveg: a bankkártya-terminál napi zárása. Ide írhatsz mást is (riasztó, lámpák, kulcs).

## A Beállítások „Fizetési módok" lapfüle

A Beállítások **Pénz és eladás** csoportjában a **Fizetési módok** lapfül azt a listát tartalmazza, amelyből a munkalapon, az árukiadáson, a rendelésen és a számla ablakában a **Fizetési mód** választható.

- A szövegmezőbe soronként egy fizetési módot írj.
- A **Nincs** mindig választható, azt nem kell felvenned.
- A munkalapon a mód neve tárolódik. Ha átnevezel egy módot, a régi lapokon a régi név marad.
- Üresen hagyva az alap lista jön vissza.

> **Fontos:** A két lapfül összetartozik. Ha új fizetési módot veszel fel, és az készpénznek vagy kártyának számít, írd be a **Házipénztár** lapfülön a megfelelő sorba is. Különben a pénztár utalásként kezeli, és nem számolja bele a kasszába.

## Szabályok, amiket a rendszer betart

- **Semmi nem törlődik véglegesen.** A törlés jelölés, és minden változás a változásnaplóba kerül: ki, mikor, mi volt előtte.
- **A kivét és a kifizetés mindig csökkenti a kasszát,** akármilyen előjellel írod be az összeget.
- **A kivéthez név kell.** Név nélkül a kivét nem menthető.
- **Jövőbeli napra nem írható tétel,** és jövőbeli nap nem számolható meg.
- **A várható készpénz** = nyitó + készpénzes bevétel + befizetés − kifizetés − kivét. **Az eltérés** = megszámolt − várható.
- **A kártya és az utalás** a forgalomba számít, a kasszába nem.
- **A számlaszám magától beíródik.** Számla kiállítása után a munkalap számla nélküli pénztártételei megkapják a számla számát.
- **Több eszköznél eszközönként külön tétel** készül, az eszköz nevével.
- **Az ügynök pénzt nem mozgat.** Csak javasol; a javítás a te gombnyomásodra történik.
- **A Ma lapfül percenként frissül,** így a másik gépen rögzített tétel is megjelenik.

## Ha valami nem megy

- **A kivétnél nem tudok menteni.** Válaszd ki, ki vette ki a pénzt. Ha nincs kit választani, a Beállítások **Házipénztár** lapfülén vedd fel a jogosultakat.
- **A kártyás fizetés készpénzként jelent meg (vagy fordítva).** Az ügynök egyeztetése felajánlja az átírást. Ha rendszeresen előfordul, ellenőrizd a **Készpénzes** és a **Kártyás fizetési módok** beállítását.
- **A munkalap fizetve van, de nincs a pénztárban.** Az **Ügynök** lapfül **Nyitott ügyek** listájában a **Beírás** gombbal pótolhatod.
- **Az import azt írja, nem talált tételsort.** A táblában a **Dátum** fejlécnek az A oszlopban kell állnia.
- **Az import szerint a fájl nem érkezett meg.** A fájl nagyobb lehet, mint amit a szerver feltöltésre enged. Legfeljebb 60 MB-os fájl tölthető fel.
- **Rossz napra került a tétel.** Nyisd meg, állítsd át a **Nap** mezőt, és mentsd. Mindkét nap összege újraszámolódik.

## GYIK

### Miért tiszteleg az asszisztens, amikor pénzt rögzítek?

Mert pénz került a kasszába. Új **bevételnél** és **befizetésnél** (a pénztár űrlapján vagy a gyors felvitellel), és amikor egy munkalapot, napi árukiadást vagy rendelést kifizettek, a figura előbb örömében felugrik, aztán **vigyázzba áll**: a két lába előbújik és összecsattan (bokacsattintás), kihúzza magát, és a jobb kezével határozottan **tiszteleg** – közben egy frappáns mondatot mond a buborékban (több mint száz közül véletlenszerűen, kétszer egymás után nem ugyanazt). Tétel módosítására, kivétre és kifizetésre nem tiszteleg. Ha nyitva van a csevegő, csak tiszteleg, nem szól közbe.

### Miért szól az asszisztens a bankkártya-terminálról, amikor lezárom a napot?

Hogy ne maradjon el a terminál napi zárása. A figyelmeztetés minden zárás után megjelenik, és addig marad, amíg a **Megvan ✓** gombbal vissza nem igazolod. A szövege átírható, a figyelmeztetés kikapcsolható a **Beállítások › Házipénztár** lapfülön.

### Ki tudom nyomtatni a napi zárást?

Igen. A **KP – kassza** lapfülön a **Zárás nyomtatása** gombbal, vagy a zárás után az asszisztens buborékjából. Korábbi nap zárása is nyomtatható: lépj a napra a nyilakkal, vagy a felső fejléc nyomtató ikonjánál válaszd a **Pénztárzárás** nyomtatványt és a napot.

### Tudok papírt adni egy kivétről vagy kifizetésről?

Igen. Nyisd meg a tételt, és kattints az **Igazolás** gombra: megnyílik a **Pénztártétel-igazolás** nyomtatvány, két aláírással. Ez belső nyilvántartás, nem szigorú számadású bizonylat.

### Mi a különbség a kivét és a kifizetés között?

A **Kivét** az, amikor valaki a cégtől pénzt vesz ki a kasszából – ehhez mindig név tartozik. A **Kifizetés** az, amikor a kasszából egy külső félnek fizetsz, például beszállítónak vagy futárnak.

### Mire való a befizetés?

Olyan pénzre, ami a kasszába kerül, de nem bevétel. Tipikusan váltópénz. A **Befizetés** a tétel ablakának tetején, a típusok között választható.

### Honnan jön a nyitó összeg?

Az előző nap záró készpénzéből. Ha az előző napot megszámoltad, a megszámolt összeg a nyitó; ha nem, a várható összeg.

### Kell minden nap új napot nyitnom?

Nem. Minden nap magától megnyílik.

### Mi történik, ha elfelejtem lezárni a napot?

A nap nyitva marad, a következő nap ettől még megnyílik. Ha a napon volt tétel, az oldal tetején figyelmeztető sáv jelzi, a **Megszámolom és lezárom** gombbal.

### Lezárhatom a napot számolás nélkül?

A **Nap zárása** gomb címletek beírása nélkül is zár, de ekkor a megszámolt összeg nulla lesz: a rendszer a teljes várható összeget hiánynak mutatja, és a következő nap nyitója is nulla lesz. Zárás előtt ezért mindig számolj.

### Miért hiányzik pénz a kasszából?

Nyisd meg az **Ügynök** lapfület, és nézd meg a nap egyeztetését. Hiánynál a jellemző ok egy be nem írt kivét vagy kifizetés, egy készpénzként rögzített kártyás fizetés vagy egy kétszer beírt tétel.

### Miért van több pénz a kasszában, mint kellene?

Többletnél a jellemző ok egy be nem írt készpénzes fizetés, vagy egy kártyásként rögzített készpénzes. Az **Ügynök** lapfül megkeresi, melyik munkalap vagy számla pénze hiányzik a pénztárból.

### Javít az ügynök magától?

Nem. Csak megállapít és javasol. Minden javítás egy gomb, amit neked kell megnyomnod.

### Módosíthatok lezárt napon?

Igen. Az ablak figyelmeztet, a változás naplózódik, a nap várható összege és eltérése pedig újraszámolódik.

### Hogyan nyitok újra egy lezárt napot?

A **KP – kassza** lapfülön vagy a **Ma** lapfül lezárást jelző sávjában kattints az **Újranyitás** gombra, majd erősítsd meg. Az újranyitás is bekerül a változásnaplóba.

### Visszahozható a törölt tétel?

Igen. Az **Ügynök** lapfülön a nap egyeztetésénél a **Törölt tétel** megállapításnál ott a **Visszaállítás** gomb.

### Mit jelent a sor elején a pipa?

Megjelölést: a kipipált sor áthúzva látszik. Egyeztetésnél arra jó, hogy lásd, mit néztél már meg. A tétel összegét nem érinti, és nem törli.

### Hogyan rögzítek előleget?

A munkalap **Fizetés** ablakában válaszd az **Előleg / részfizetés** lehetőséget, és írd be az összeget. A pénztárban a leírás elé **Előleg –** kerül.

### Mi történik, ha az ügyfél részben készpénzzel, részben kártyával fizet?

A **Fizetés** ablakban válaszd a **Vegyes** módot, és írd be külön a készpénzes és a kártyás részt. A pénztárba egy tétel kerül, két összeggel.

### Hová kerül az utalásos fizetés?

Az utalás oszlopba. A forgalomba beleszámít, a kassza készpénzét nem változtatja.

### Mi az a „Nem került kasszába" sor?

Jelölés arról, hogy egy munkalap fizetése megtörtént, de kérésre nem lett belőle pénztártétel. Az összege tájékoztató, a kasszát nem változtatja.

### Miért kérdezi a rendszer, hogy bekerüljön-e a házipénztárba?

Mert a munkalapot kézzel állítottad fizetettre, vagy megnövelted az előleget, és a pénz még nincs a pénztárban. Ha nem kéred ezt a kérdést, a Beállítások **Házipénztár** lapfülén kikapcsolható.

### Be kell írnom a számlaszámot a pénztárba?

Nem. A számla kiállítása után a munkalap pénztártételei maguktól megkapják a számlaszámot. A korábbi, számla nélküli tételeket az **Ügynök** lapfül **Mind beírása** gombja pótolja.

### Tévesen rögzítettem egy fizetést. Hogyan vonom vissza?

A munkalapon vedd ki a **Fizetve** pipát. A megjelenő buborék elmondja, mi történik, és – ha a lapot fizetés zárta le – felajánlja a **Fizetés visszavonása** gombot. Utána mentsd a lapot. A pénztártétel nem törlődik magától: a Házipénztárban külön kell törölnöd.

### Mi történik, ha kétszer írom be ugyanazt a munkalapot?

A gyorssor figyelmeztet, ha a munkalapra már van pénztártétel, és megerősítést kér. Ha mégis kétszer került be, az ügynök **Kétszer rögzítve?** megállapítása jelzi.

### Hogyan keresek rá egy számlaszámra vagy összegre?

Írd be a fejléc keresőmezőjébe, és nyomd meg az [[Enter]] billentyűt. A rendszer felismeri, hogy számlaszámot, munkalapszámot, összeget vagy szavakat írtál.

### Hogyan nézem meg, ki mennyit vett ki a hónapban?

A **Riport** lapfülön válaszd az **Ez a hónap** időszakot. A **Kivét személyenként** kártya névenként mutatja az összeget és az alkalmak számát.

### Hogyan töltöm le a tételeket Excelbe?

A **Riport** lapfülön az **Excel (CSV) letöltés** gombbal a kiválasztott időszakot, a **Napló** lapfülön az **Excel (CSV)** gombbal az éppen látható napokat.

### Elrontja az import a már rögzített tételeimet?

Nem. Az import csak új sorokat ad hozzá, és amit a rendszerben már megszámoltál vagy lezártál, azt a napot nem írja felül. A visszavonás is csak az importált sorokat törli.

### Feltölthetem újra ugyanazt a táblát?

Igen. Ami már bent van, kimarad; csak az új sorok kerülnek be. Az előnézet megmutatja, mennyi az új.

### Hogyan vonom vissza az importot?

Az **Import** ablak alján a **Korábbi import visszavonása…** hivatkozással. Kétszer kell rákattintani: másodszor megerősítésként.

### Hogyan veszek fel új fizetési módot?

A Beállítások **Fizetési módok** lapfülén írd be új sorba. Ha készpénznek vagy kártyának számít, a **Házipénztár** lapfülön is vedd fel a megfelelő sorba.

### Ki nyithatja meg a pénztár beállításait?

Csak adminisztrátor. A pénztár oldalát és a fizetés ablakát minden kolléga használhatja.

## Túra: penztar

- `.sdh-pt__cim` | A mai nap | A dátum, a nap állapota (nyitott vagy lezárt) és a kasszában most várható készpénz.
- `.sdh-pt__gombok` | Új tétel | Bevétel, kivét vagy kifizetés rögzítése ablakban. Az Import gomb a régi Excel-táblát tölti be.
- `[data-pt-kereso]` | Keresés a pénztárban | Számlaszámra, munkalap sorszámra, névre vagy összegre kereshetsz a teljes múltban.
- `.sdh-pt__fulek` | Lapfülek | Ma, Napló, KP – kassza, Riport, Statisztika és Ügynök. A rendszer megjegyzi, melyiken jártál utoljára.
- `[data-pt-lap="ma"] .sdh-pt-kpik` | A nap számai | Nyitó készpénz, készpénzes és kártyás bevétel, kivét és kifizetés, a kasszában várható összeg és a napi forgalom.
- `[data-pt-gyors]` | Gyorssor | Írd be, mi történt és mennyi, majd Enter. A munkalap sorszámából a rendszer kitölti a többit.
- `[data-pt-lap="ma"] .sdh-pt-tabla` | A mai tételek | Egy sor egy pénzmozgás. A sorra kattintva módosíthatod; a sor eleji pipával megjelölheted.
- `[data-pt-ful="kp"]` | Számolás és zárás | Itt számolod meg a kasszát címletenként, és itt zárod a napot. Az eltérés azonnal látszik.
- `[data-pt-ful="ugynok"]` | Egyeztető ügynök | Ha a kassza nem stimmel, itt derül ki, mi hiányzik vagy mi került be rosszul.
