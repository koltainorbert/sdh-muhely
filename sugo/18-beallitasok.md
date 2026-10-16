---
kulcs: beallitasok
cim: Beállítások
csoport: Rendszer
modul: beallitasok
szakaszok: demo, kalauz, rendszer, sebesseg
kulcsszavak: beállítások, beállítás, lapfül, fül, mentés, adminisztrátor, admin, demó, demo, próbaadat, mintaadat, tesztadat, stíluskalauz, színek, névjegy, verzió, verziószám, séma, internet nélkül, offline, ctrl s
osszefoglalo: A Beállítások egyetlen oldal, ahol témánként külön lapfülön áll minden állítható adat; itt van a demó adatok betöltése, a stíluskalauz és a névjegy is.
---

## Mire való

A **Beállítások** menüpont a rendszer egyetlen adminfelülete. Minden állítható adat itt van: a cég neve és logója, a munkalap számozása és állapotai, a fizetési módok, a számlázás, a levelezés, a kollégák, a naptár, az asszisztens és a készülékadatok forrásai.

Az oldal egyetlen lap, témánként külön lapfüllel. Egy lapfül egy téma, és minden lapfül külön ment.

Ez a fejezet a Beállítások működését írja le, felsorolja az összes lapfület, és részletesen háromról szól: **Demó adatok**, **Stíluskalauz**, **Névjegy**. A többi lapfül leírása annak a menüpontnak a fejezetében van, amelyikhez tartozik.

## Hol találod

A bal oldali menü legalján áll a **Beállítások** menüpont, fogaskerék ikonnal. [Beállítások megnyitása](modul:beallitasok)

- **Csak adminisztrátor látja.** A kolléga menüjében a menüpont nem jelenik meg, és az Áttekintésen sincs csempéje.
- Ha kolléga a címet kézzel írja be, az **Ehhez nincs jogosultságod.** oldalt kapja.
- A műhely-felületről a Beállítások nem visz át a WordPress vezérlőpultjába (wp-admin): ugyanabban a keretben dolgozol, mint bárhol máshol. A wp-adminból megnyitva ugyanez az oldal jelenik meg.

A jogosultságokról bővebben: [Mentés, jogosultság, biztonság](sugo:mentes-biztonsag).

## A Beállítások oldal felépítése

Az oldal tetején a **Beállítások** cím áll, alatta a lapfülek hat sorban, csoportonként:

- **Cég és megjelenés**
- **Munkalap**
- **Pénz és eladás**
- **Kapcsolat és csapat**
- **Készülékadatok**
- **Rendszer**

A kikapcsolt modul lapfüle nem látszik (például kikapcsolt SMS-értesítéseknél nincs **SMS-értesítések** lapfül). A modulokat a **Modulok** menüpontban kapcsolod ki és be: [Modulok](sugo:modulok).

Az éppen nyitott lapfül kiemelve látszik. Ha az egeret egy lapfül fölé viszed, rövid leírás jelenik meg róla. A lapfülek alatt a kiválasztott lapfül tartalma áll: felül a neve és egy mondat arról, mire való, alatta a dobozok a mezőkkel, legalul a **Mentés** gomb.

## Az összes lapfül

A Beállítások lapfülei, abban a sorrendben, ahogy az oldalon állnak. Az utolsó oszlop azt a súgófejezetet mutatja, amely a lapfület részletesen leírja.

| Lapfül | Mire való | Részletes leírás |
|---|---|---|
| **Logó és megjelenés** | A rendszer logója, neve, színei, a sarkok, az oldalsáv, a betűtípus és az alap betűméret, a popupok neon kerete. | [Megjelenés](sugo:megjelenes) |
| **Áttekintés csempéi** | Melyik menüpont csempéje látszik az Áttekintés tetején, milyen sorrendben és felirattal. | [Áttekintés](sugo:attekintes) |
| **Nyitvatartás és óra** | Az üzlet nyitvatartása naponként, és a fejléc órája a zárásig hátralévő idővel. | [Megjelenés](sugo:megjelenes) |
| **Ügyféloldal (RMA)** | Amit az ügyfél a QR-kód beolvasása után lát: cím, értesítés, megjelenés, nyelvek, elérhetőség. | [Üzenetek és ügyféloldal](sugo:uzenetek-rma) |
| **Számozás** | A munkalapszám kezdőértéke, előtagja és kitöltése. | [Munkalapok](sugo:munkalapok) |
| **Állapotok** | A munkalap állapotai: név, szín, és hogy melyik számozott, lezárt, alapértelmezett. | [Munkalapok](sugo:munkalapok) |
| **Hibasor-állapotok** | A munkalap hibasorainak saját állapotlistája. | [Munkalapok](sugo:munkalapok) |
| **Gyakori hibák** | A munkalap **Hibák** lapfülén egy kattintással felvehető hibák listája. | [Munkalapok](sugo:munkalapok) |
| **Gyakori megjegyzések** | A munkalap **Megjegyzés** lapfülén popupból választható, előre megírt megjegyzések. | [Munkalapok](sugo:munkalapok) |
| **Űrlapok** | Mi látszódjon a munkalap, a rendelés, a napi árukiadás és a két számla ablakában; a számlára magától kerüljenek-e a tételek. Ugyanez az űrlapok fogaskerekével. | [Űrlapok beállítása](sugo:urlapok) |
| **Fizetési módok** | A munkalapon, az árukiadáson és a rendelésen választható fizetési módok. | [Pénztár](sugo:penztar) |
| **Számlázás** | A Számlázz.hu-kapcsolat, a számlatömbök, a helyi nyomtatvány és a kiállító adatai, a bevizsgálási díj szövege. | [Számlázás](sugo:szamlazas) |
| **Nyomtatványok** | Minden nyomtatható papír: átvételi, munkalap, árajánlat, szállítólevél, bianko lap, pénztárzárás, listák – logó, betű, méret, szövegek, példányszám, felajánlás. | [Nyomtatás, PDF és e-mail](sugo:nyomtatas) |
| **Házipénztár** | Személyek, címletek, a fizetési módok besorolása, kategóriák, a pénztár-ajánlat, a zárás utáni emlékeztető. | [Pénztár](sugo:penztar) |
| **Árukiadás és rendelések** | Helyszínek (üzletek), beszállítók, a rendelés állapotainak neve, az értesítő szövege, a listák mérete. | [Napi árukiadás](sugo:arukiadas) |
| **Levelezés** | E-mail-fiókok (IMAP / SMTP), aláírás, jelszavas zár, a levélszűrő és a rendező ügynök. | [Levelezés](sugo:levelezes) |
| **Csapat** | Többfelhasználós csevegés vagy üzenőfal, a fal neve, a kollégák névsora; innen nyílik a kollégák és a csoportok kezelése. | [Csapat](sugo:csapat) |
| **Naptár** | A naptár alapnézete és órasávja, a kategóriák, az emlékeztetők, az e-mail értesítések és a napi összefoglaló. | [Naptár](sugo:naptar) |
| **Asszisztens** | Az AI-asszisztens: be- és kikapcsolás, neve, kérdéskeret, API-kulcs, engedélyek; innen nyílnak a javaslatok, a tudástár és a mentések. | [Asszisztens](sugo:asszisztens) |
| **IMEI-lekérdezés** | A fizetős IMEI-szolgáltató végpontja és kulcsa, és az ingyenes ellenőrző oldalak gombjai. | [Eszközök](sugo:eszkozok) |
| **TAC adatbázis** | Az IMEI elejéből ez mondja meg a gyártót és a típust: állapot, betöltés. | [Eszközök](sugo:eszkozok) |
| **Mentés** | A napi automatikus mentés, a megőrzött mentések száma, kézi mentés és letöltés. | [Mentés, jogosultság, biztonság](sugo:mentes-biztonsag) |
| **Jogosultságok** | Egyszerre egy ablakban szerkeszthető lap: melyik rekordtípus zárol (munkalap, ügyfél, eszköz), és a zárolást felülbíráló kód. | [Munkalapok](sugo:munkalapok) |
| **Sebesség** | A felület stíluslapjai és szkriptjei egy-egy fájlban: kevesebb kérés, gyorsabb oldalváltás. | ez a fejezet |
| **Demó adatok** | Fiktív ügyfelek, készülékek, munkalapok és termékek a kipróbáláshoz. | ez a fejezet |
| **Stíluskalauz** | A rendszer színei és elemei egy helyen. | ez a fejezet |
| **Névjegy** | Verziók, címek, és ami internet nélkül is működik. | ez a fejezet |

Egy lapfül csak akkor jelenik meg, ha a hozzá tartozó modul be van töltve.

## Lépésről lépésre: egy beállítás módosítása

1. Nyisd meg a **Beállítások** menüpontot.
2. Kattints arra a lapfülre, amelyik a témához tartozik.
3. Írd át, amit kell. A **Mentés** gomb mellett megjelenik a **Nem mentett módosítás.** felirat.
4. Nyomd meg a **Mentés** gombot, vagy a [[Ctrl]]+[[S]] billentyűket (Macen [[Cmd]]+[[S]]).
5. A gomb mellett előbb **Mentés…**, majd **Elmentve.** áll. A lapfül ezután újratöltődik, és a mentett állapotot mutatja – ugyanott, ahol a görgetésben tartottál.

Ha mentés nélkül másik lapfülre kattintasz, a lapfülek alatt sáv jelenik meg: **Ezen a lapfülön nem mentett módosítás van.** Három gomb közül választasz:

- **Mentés és tovább** – elmenti, aztán átlép a másik lapfülre.
- **Elvetés és tovább** – a módosítás elvész, átlép a másik lapfülre.
- **Maradok** – a sáv eltűnik, folytathatod a szerkesztést.

> **Fontos:** A sáv csak lapfülváltáskor kérdez. Ha a Beállításokból egy másik menüpontra lépsz, vagy bezárod a böngészőlapot, a nem mentett módosítás kérdés nélkül elvész.

## Mezők és gombok

A Beállítások oldal közös elemei – ezek minden lapfülön ugyanúgy működnek:

- **Lapfülek** – a téma kiválasztása. Minden lapfülnek saját címe van, könyvjelzőbe tehető.
- **Mentés** – az éppen nyitott lapfület menti.
- **Állapotjelző a Mentés mellett** – **Nem mentett módosítás.**, **Mentés…**, **Elmentve.**, vagy pirossal a hiba szövege.
- **Feltöltő gombok** (logó, háttérkép, háttérvideó) – a műhely-felület saját, egyszerű feltöltője. Kép: JPG, PNG, GIF vagy WebP; videó: MP4 vagy WebM. A fájl a WordPress médiatárába kerül.
- **Színválasztó és színkód** – össze vannak kötve: bármelyiket állítod, a másik követi.

Néhány lapfülnek nincs közös **Mentés** gombja: az **Asszisztens**, a **TAC adatbázis** és a **Demó adatok** saját gombokkal dolgozik, a **Stíluskalauz** és a **Névjegy** pedig csak megmutat.

## Szabályok, amiket a rendszer betart

- **Lapfülenként külön mentés.** A **Mentés** csak az éppen nyitott lapfület menti. A többi lapfül adataihoz nem nyúl.
- **A mentő csak azt írja, amit az űrlap hozott.** Egy lapfül mentése nem ürít ki olyan beállítást, amely nem azon a lapfülön van.
- **Csak adminisztrátor menthet.** Másnak a válasz: **A beállításokhoz adminisztrátori jog kell.**
- **A kulcsok és jelszavak nem jelennek meg újra.** A mentett jelszó vagy kulcs mezője üres marad, helyőrző jelzi, hogy van mentett érték. Újat csak akkor írj be, ha cserélni akarod. A fizetős IMEI-szolgáltató kulcsa kivétel: az a mezőjében olvasható.
- **A változás azonnal él.** A kollégák a következő oldalbetöltéskor látják.
- **Nincs átjárás a wp-adminba.** A Beállítások, a mentés és a feltöltés is a műhely-felületen marad; mentés után ugyanarra a lapfülre térsz vissza.

## Sebesség

A **Beállítások › Sebesség** lapfülön egyetlen kapcsoló van: **a felület CSS- és JS-fájljai egy kérésben töltődjenek be**. Alapból be van kapcsolva.

Miért van rá szükség: a műhely-felület több mint húsz stíluslapot és szkriptet használ, és mindegyik kell minden oldalon, mert a popupok (munkalap, pénztár, nyomtatás, napló, levélíró) bárhol megnyílhatnak. Ez önmagában nem sok adat, de **sok kérés** – és egy helyi szerveren (LocalWP) minden egyes kérés sokat tart, akkor is, ha a fájl pici.

Bekapcsolva a rendszer ezeket a fájlokat egyetlen stíluslapba és egyetlen szkriptbe fűzi össze:

- a kód **karakterre ugyanaz**, és ugyanabban a sorrendben fut – nincs tömörítés, nincs átírás,
- a csomag a **feltöltési mappába** kerül (`wp-content/uploads/sdh-muhely-csomag/`), nem a bővítmény mappájába,
- a fájl neve a verzióból képződik, így **frissítés után magától új csomag készül** – nincs mit üríteni, és a böngésző sem tölt be régit,
- a régi csomagokat a rendszer magától törli,
- csak a **saját** fájljaihoz nyúl: a WordPress és a többi bővítmény eszközeihez nem.

Ha a feltöltési mappa nem írható, a lapfül ezt kiírja, és a felület – észrevétlenül – a régi módon, külön fájlokból töltődik be. A kapcsoló ilyenkor is bekapcsolva maradhat: amint a mappa írhatóvá válik, magától működni kezd.

**Mikor kapcsold ki?** Ha a felület fejlesztésén dolgozol, és azt szeretnéd, hogy a böngésző fejlesztői eszközeiben külön fájlonként lásd a stílusokat és a szkripteket. A napi munkához hagyd bekapcsolva.

Mit NEM gyorsít ez: az adatbázis-lekérdezéseket és a munkalapok mennyiségét. Ha a lista lassú, a [Hibaelhárítás](sugo:hibaelharitas) fejezet szól róla.

## Demó adatok

A **Beállítások › Demó adatok** lapfül kitalált adatokkal tölti fel a rendszert, hogy a felületet ki lehessen próbálni vagy be lehessen mutatni.

Mit tölt be:

- **10 kitalált ügyfelet**, mindegyikhez **egy készüléket** és **egy munkalapot**. Minden mező ki van töltve: címek, telefonszámok, IMEI, zárkód, minta, garancia, tartozékok, megjegyzések.
- A tíz munkalap az alapértelmezett **nyolc állapot mindegyikét** használja, hibasorokkal, tételekkel és fizetési adatokkal. A dátumok a mai naphoz igazodnak: lesz mai, lejárt határidejű és lezárt lap is.
- **20 kitalált mintaterméket** a Termékek menüpontba, nyitókészlettel.

### Lépésről lépésre: demó adatok betöltése

1. Nyisd meg a **Beállítások › Demó adatok** lapfület.
2. Nyomd meg a **Demó adatok betöltése** gombot.
3. Az oldal tetején megjelenik az eredmény, például: *Demó adatok betöltve: 10 új ügyfél, 10 új eszköz, 10 új munkalap.*

Ha a gombot újra megnyomod, semmi nem jön létre kétszer. Ilyenkor az üzenet: **A demó adatok már mind megvannak, semmi nem jött létre újra.** A lapfül szövege azt is kiírja, hány demó ügyfél van éppen a rendszerben.

### Amit a demó adatokról tudni kell

- **Valódi adathoz nem nyúl.** A demó rekordok belső azonosítója `demo-` kezdetű, a betöltő csak ezeket keresi és hozza létre.
- **A számozott demó munkalapok valódi munkalapszámot kapnak** a sorozatból. Emiatt éles rendszerbe ne töltsd be: a demó lapok elfoglalnának néhány sorszámot.
- **Ha egy állapotot átneveztél vagy töröltél,** az a demó munkalap az alapértelmezett állapotot kapja.
- **Eltávolító gomb nincs.** A demó adatok a többi adat között maradnak. Az ügyfelet inaktívvá teheted, a munkalapot érvénytelen állapotba teheted, de egy gombbal törölni nem lehet őket.

## Stíluskalauz

A **Beállítások › Stíluskalauz** lapfül egyetlen oldalon megmutatja a felület építőelemeit, a most beállított színekkel. Menteni itt nincs mit. Öt doboz van rajta:

- **Színek** – tizennégy színminta a nevével: a kiemelő szín három árnyalata, a vászon, a felületek, a keretek, a szövegszínek, és a három jelzőszín (**Siker**, **Hiba**, **Figyelem**).
- **Gombok és címkék** – elsődleges, másodlagos és letiltott gomb, címke és inaktív címke.
- **Értesítések** – egy sikerüzenet, egy figyelmeztetés és egy hibaüzenet mintája.
- **Űrlapmezők** – szövegmező, legördülő lista, jelölőnégyzet.
- **Táblázat** – egy mintatáblázat, kitalált sorokkal.

Mire jó:

- **Színváltás után ellenőrzés.** Ha a **Logó és megjelenés** lapfülön új kiemelő színt állítottál be, itt látod, olvasható-e minden.
- **Minden módban nézd meg:** világosban, sötétben és a **Papír** módban is (profilmenü).
- **Itt látszik először, ha valami elromlik.** Ha egy frissítés után furcsán néz ki a felület, a kalauz megmutatja, melyik elem a hibás.

A színek neve mellett álló `--sdh-…` kezdetű kód a szín belső változóneve; a napi munkához nem kell.

## Névjegy

A **Beállítások › Névjegy** lapfül megmondja, melyik verzió fut, és hol érhető el a rendszer. Hibabejelentésnél ezt add meg.

A **Verziók** doboz sorai:

| Sor | Mit mutat |
|---|---|
| **Rendszer** | A rendszer neve, ahogy a **Logó és megjelenés** lapfülön megadtad. |
| **Plugin verzió** | A bővítmény verziószáma. Frissítés után ennek kell megváltoznia. |
| **Séma verzió** | Az adatbázis szerkezetének verziója. Ritkábban változik, mint a bővítményé – nem hiba, ha a kettő eltér. |
| **Műhely-felület** | A műhely címe. Ezt add oda a kollégáknak. |
| **WordPress** | A WordPress verziója. |
| **PHP** | A szerver PHP-verziója. |

Az **Ami internet nélkül is megy** doboz szerint:

- **Megy enélkül is:** a felvitel, a munkalap, a pénztár és a helyi nyomtatvány. Az IMEI-ből a gyártót, a gyári számot és a kereskedelmi nevet a rendszer a saját nyilvántartásából és a helyi TAC-adatbázisból tölti ki.
- **Internet kell hozzá:** a levelezés, a Számlázz.hu-számla és az asszisztens. A sorozatszámot, a garanciát és a gyártási dátumot fizetős IMEI-szolgáltató nélkül kézzel kell beírni.

A frissítésről és a verziók ellenőrzéséről: [Telepítés és frissítés](sugo:telepites).

## Ha valami nem megy

- **„Lejárt a munkamenet – töltsd újra az oldalt, és próbáld újra."** Túl régóta állt nyitva az oldal. Töltsd újra az [[F5]] billentyűvel, és mentsd újra.
- **„A beállításokhoz adminisztrátori jog kell."** Nem adminisztrátorként vagy belépve.
- **„A fájl nem érkezett meg (túl nagy, vagy megszakadt a feltöltés)."** A kép vagy a videó nagyobb, mint amit a szerver enged. Tölts fel kisebbet.
- **„A feltöltés nem sikerült: …"** A fájl típusa nem megengedett, vagy a szerver elutasította. A kettőspont utáni szöveg megmondja az okot.

További elakadások: [Hibaelhárítás](sugo:hibaelharitas).

## GYIK

### Miért nem látom a Beállítások menüpontot?

Mert kollégaként vagy belépve. A Beállításokat csak adminisztrátor látja. Ha szükséged van rá, az adminisztrátor a **Csapat** menüpontban adminisztrátorrá tehet.

### Egy mentéssel az összes lapfül elmentődik?

Nem. A **Mentés** csak az éppen nyitott lapfület menti. Ha két lapfülön is módosítottál, mindkettőn külön kell menteni.

### Elvész a módosításom, ha másik lapfülre kattintok?

Nem azonnal. A rendszer megkérdezi: **Mentés és tovább**, **Elvetés és tovább** vagy **Maradok**. Csak akkor vész el, ha az elvetést választod.

### Van gyorsbillentyű a mentéshez?

Igen: [[Ctrl]]+[[S]], Macen [[Cmd]]+[[S]]. Az éppen nyitott lapfület menti.

### Miért töltődik újra a lapfül mentés után?

Hogy a mentett állapotot lásd: az új logót, az új színeket, vagy azt, hogy a kulcs meg van adva. A görgetés helye megmarad.

### Hol veszek fel új kollégát?

A kollégák a **Csapat** menüpontban vannak. A **Beállítások › Csapat** lapfülről a **Kollégák kezelése** gomb visz oda. Részletek: [Csapat](sugo:csapat).

### Miért üres a jelszó vagy a kulcs mezője, pedig megadtam?

Biztonsági okból a mentett jelszó és kulcs nem kerül vissza a képernyőre. A mező helyőrzője jelzi, hogy van mentett érték. Csak akkor írj bele, ha cserélni akarod.

### Betölthetem a demó adatokat az éles rendszerbe?

Ne tedd. A számozott demó munkalapok valódi munkalapszámot foglalnak el, és a kitalált ügyfelek összekeverednek a valódiakkal.

### Hogyan törlöm a demó adatokat?

Egy gombbal sehogy, erre nincs felület. Az ügyfeleket inaktívvá teheted, a munkalapokat érvénytelen állapotba teheted. Ezért érdemes a demót külön próbarendszeren tartani.

### Kétszer megnyomtam a demó gombot, most minden duplán van?

Nem. A betöltő felismeri, ami már megvan, és nem hozza létre újra. Az üzenet is ezt írja ki.

### Mire jó a Stíluskalauz?

Arra, hogy egy helyen lásd a felület összes elemét a beállított színekkel. Színváltás után itt ellenőrizheted, hogy minden olvasható-e.

### Hol látom, melyik verzió fut?

A **Névjegy** lapfülön, a **Plugin verzió** sorban.

### Miért más a séma verziója, mint a bővítményé?

Mert az adatbázis szerkezete nem változik minden frissítéssel. Ez nem hiba.

### Működik a rendszer, ha elmegy az internet?

Ha a rendszer a helyi hálózaton fut, a felvitel, a munkalap, a pénztár és a helyi nyomtatvány megy. A levelezés, a Számlázz.hu-számla és az asszisztens nem.

### Ki látja, amit a Beállításokban átírok?

Mindenki, aki a rendszert használja: ezek a cég közös beállításai. A saját gépére szóló megjelenést mindenki a profilmenüben állítja.

## Túra: beallitasok

- `.sdh-fejlec` | A Beállítások oldal | Minden állítható adat ezen az egy oldalon van. Csak adminisztrátor látja.
- `.sdh-beall__menu` | A lapfülek | Hat sorban, csoportonként állnak a lapfülek. Egy lapfül egy téma.
- `.sdh-beall__csoport` | A csoportok | A sor elején a csoport neve áll, például Cég és megjelenés vagy Munkalap. Ez segít megtalálni, amit keresel.
- `.sdh-pill.is-aktiv` | A nyitott lapfül | A kiemelt lapfül tartalmát látod lent. Másik lapfülre kattintva az ő beállításai jelennek meg.
- `.sdh-beall__fej` | Mire való a lapfül | A lapfül neve és egy mondat arról, mit lehet itt beállítani.
- `.sdh-beall__tartalom` | A beállítások | Itt vannak a dobozok a mezőkkel. Amit átírsz, az csak a mentéssel lép életbe.
- `[data-sdh-beall-ment]` | Mentés | Ez a gomb csak az éppen nyitott lapfület menti. Gyorsabban: Ctrl+S.
