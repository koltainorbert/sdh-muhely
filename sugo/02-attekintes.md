---
kulcs: attekintes
cim: Áttekintés
csoport: Napi munka
modul: attekintes
szakaszok: csempek
kulcsszavak: áttekintés, kezdőképernyő, főoldal, kezdőlap, csempe, csempék, kártya, rács, munkalap-rács, lista, táblázat, nézet, szűrő, szűrés, keresés, rendezés, oszlop, oszlopok, részletek, részletpanel, kijelölt sor, jelzés, zászló, összesítő sor, napi árukiadás, új munkalap
osszefoglalo: Az Áttekintés a kezdőképernyő – fent a gyors gombok és a modulok csempéi, alatta az összes munkalap szűrhető, rendezhető rácsa a kijelölt lap részleteivel.
---

## Mire való

Az **Áttekintés** a rendszer kezdőképernyője. Belépés után ide érkezel, és a napi munka nagy része innen elvégezhető.

Három része van, fentről lefelé:

- **Gombsor** a cím mellett: új munkalap, ügyfél, termék, szolgáltatás, árukiadás, rendelés.
- **Modulcsempék**: minden menüpont egy csempe, a legfontosabb számával.
- **Munkalap-rács**: az összes munkalap egy táblázatban, nézetekkel, szűrőkkel, kereséssel. Alatta a kijelölt munkalap részletei.

A lapok popupban nyílnak, mentés után a rács magától frissül.

## Hol találod

Az oldalmenü legfelső pontja: [Áttekintés](modul:attekintes). Belépés után is ez az oldal jelenik meg.

Az óra és a zárásig hátralévő idő a felső fejlécben van, minden oldalon. A betűméret csúszkája a profilmenüben (jobb felső sarok, a nevedre kattintva) és a Beállítások **Logó és megjelenés** lapfülén található.

## A fejléc gombsora

Az Áttekintés címe mellett ezek a gombok állnak:

| Gomb | Mit csinál |
|---|---|
| **+ Új munkalap** | Üres munkalap popupban. Részletesen: [Munkalapok](sugo:munkalapok). |
| **+ Új ügyfél** | Új ügyfél felvétele popupban. |
| **+ Új termék**, **+ Új szolgáltatás** | Új tétel a törzsbe. Mentés után az Áttekintésen maradsz. |
| **Napi árukiadás** | Új pultos eladás lapja, javítás nélkül. |
| **Rendelések** | Új rendelés lapja egy ügyfélnek. |
| **Csempék elrejtése** | Eltünteti a modulcsempéket, így a rács több helyet kap. Vissza: **Csempék megjelenítése**. |

A meglévő árukiadások és rendelések listáját a menüpontjukban vagy a csempéjükre kattintva találod.

## Modulcsempék

Az Áttekintés gombsora alatt minden menüpontnak van egy csempéje: a modul neve, alatta egy szám és egy rövid felirat. Kattintásra megnyílik a modul.

Alapból ezek a számok látszanak: aktív ügyfél, nyilvántartott eszköz, nyitott munkalap, olvasatlan üzenet, olvasatlan levél (és külön, hány azonnali), Ft a kasszában (várható), szolgáltatás, termék, olvasatlan csapatüzenet, mai esemény, mai árukiadás, folyamatban lévő rendelés.

Amelyik modulnak nincs száma, annak a csempéjén csak ennyi áll: *megnyitás*. A csempe kiemelve jelez, ha teendő vár: olvasatlan ügyfélüzenet vagy csapatüzenet, azonnali levél, esedékes naptáresemény.

A **nyitott munkalap** a számozott, le nem zárt javítási lapok száma. Az árajánlat, a sablon, a napi árukiadás és a rendelés nem számít bele.

Mentés és állapotváltás után a számok maguktól frissülnek, nem kell újratölteni az oldalt.

A csempék elrejtése a saját böngésződre szól. Hogy melyik csempe létezzen egyáltalán, azt a Beállítások **Áttekintés csempéi** lapfülén lehet megadni.

## A munkalap-rács

A rács az Áttekintés fő része: minden javítási munkalap és minden napi árukiadás egy-egy sor. A rendelések nincsenek benne, azok a [Rendelések](modul:rendelesek) menüpontban állnak.

Mindig csak egy oldalnyi sort tölt be, ezért sok százezer munkalapnál is gyors. A sorok azonnal megjelennek; a találatok számát és az összesítő sort a rendszer közben számolja, és ha még nem kész, egy pillanatra `…` áll a helyén (lapozó: `1 / …`). Amíg a munkalapok nem változnak, ugyanarra a szűrésre a szám azonnal jön.

### Az eszköztár

A rács fölötti sor, balról jobbra:

- **Nézetek** gomb – a kiválasztott nézet nevét mutatja. Ha a szűrést átállítottad, a név után pötty (•) áll; ha nincs kiválasztott nézet: *Egyéni szűrés*.
- **Lapozó** – első, előző, következő, utolsó oldal, közte az oldalszám (`3 / 12`).
- **Frissítés** és **Összesítő sor** (ki-be kapcsolja a rács alján az összegző sort).
- **Szűrők törlése** – minden szűrőt és a keresést is törli, és visszaáll a *Minden munkalap* nézetre. A gombon szám mutatja, hány szűrő él.
- A találatok száma, majd a **Keresés a látható oszlopokban…** mező.
- **Sorok száma** – 25, 50, 100 vagy 200 sor oldalanként (alap: 50).
- **Oszlopok** – melyik oszlop látszik.

### A sorok

- Egy kattintás **kijelöli** a sort: kiemelő színt és a bal szélén színes sávot kap, a szövege félkövér lesz. A részletei megjelennek alul.
- Dupla kattintás vagy [[Enter]] **megnyitja** a munkalapot szerkesztésre, popupban.
- A [[↑]] [[↓]] nyilakkal lépkedhetsz a sorok között; a [[Home]] / [[End]] az oldal első, illetve utolsó sorára visz.
- A lezárt munkalapok sora halványabb.
- A lejárt határidő kiemelve látszik – de csak a még nyitott lapoknál.
- Ha a sorra állsz, a típus, az IMEI szám, a sorozatszám, az ügyfél, a telefonszám és a cím mellett **másoló gomb** jelenik meg.
- A **Sorszám** oszlopban gondolatjel áll, amíg a lap nem kapott munkalapszámot (árajánlat, sablon).

## Nézetek

A nézet egy előre összeállított szűrés és rendezés az Áttekintés rácsában. A **Nézetek** gombra kattintva ezek közül választhatsz:

| Nézet | Mit mutat |
|---|---|
| **Minden munkalap** | Mindent, sorszám szerint csökkenően. |
| **Nyitott munkalapok** | A számozott, még le nem zárt lapokat. |
| **Elkészült – átvételre vár** | Az elkészült lapokat, és ahol több eszközből már van kész. |
| **Napi árukiadás** | Csak a pultos eladások lapjait. |
| **Csak javítás (árukiadás nélkül)** | A javítási munkalapokat, eladások nélkül. |
| **Fizetendő** | Ahol még van fizetnivaló, a legnagyobb összeggel elöl. |
| **Lejárt határidő** | A nyitott lapokat, amelyek határideje elmúlt. |
| **Ma készült** | A mai napon felvett lapokat. |
| **Árajánlatok és sablonok** | A munkalapszám nélküli lapokat. |

A nézetek az állapotlistából épülnek: ha a Beállításokban átnevezel egy állapotot, a nézet követi.

### Lépésről lépésre: saját nézet mentése

1. Állítsd be a szűrőket, a rendezést, az oszlopokat és az oldalméretet.
2. Kattints a **Nézetek** gombra.
3. Alul írd be a nevet az **Új nézet neve** mezőbe.
4. Kattints a **Mentés** gombra (vagy nyomj [[Enter]]-t).

A saját nézet a **Saját nézetek** alatt jelenik meg, és az oszlopokat is megjegyzi. A fiókodhoz tartozik: másik gépről belépve is megvan, de a kolléga nem látja. Legfeljebb 30 lehet. Módosítás után a panel **„Név” frissítése** gombja írja felül, a sor végi **×** törli.

## Oszlopok

Az Áttekintés rácsában alapból ezek az oszlopok látszanak: **Sorszám, Jelzés, Állapot, Felelős, Azonosító, Gyártó, Típus, Garancia, IMEI szám, Sorozatszám, Ügyfél, Telefonszám, Cím, Készült, Határidő, Lezárva, Fizetve, Fizetés ideje, Bruttó é., Fizetett, Fizetendő, Belső megjegyzés**.

Bekapcsolható, alapból rejtett oszlopok: **Név, Megnevezés, Fizetési mód, Létrehozva, Módosítva**.

- **Megjelenítés, elrejtés:** az **Oszlopok** gombbal. Az **Alaphelyzet** visszaállítja az eredeti elrendezést.
- **Sorrend:** fogd meg az oszlop fejlécét, és húzd a helyére.
- **Szélesség:** húzd az oszlopfej jobb szélét.

Néhány oszlop jelentése:

- **Azonosító** – az eszköz fajtája (például mobiltelefon).
- **Típus** – az eszköz típusa. Ha a lapon több eszköz van, mellette `+1`, `+2` jelzi, hány további.
- **Fizetett** – ami ténylegesen befolyt (előleg, részfizetés, teljes fizetés).
- **Fizetendő** – a bruttó érték mínusz a fizetett összeg; kifizetett lapnál nulla. Negatív is lehet, ha az előleg több, mint a tételek értéke.

Az oszlopok, a szűrők, a rendezés és az oldalméret a böngésződben megmarad: a rács legközelebb úgy nyílik, ahogy hagytad.

## Szűrés, keresés, rendezés

### Keresés

Az Áttekintés rácsa fölötti **Keresés a látható oszlopokban…** mezőbe írt szöveget a rács minden *látható* oszlopban keresi; elrejtett oszlopban nem. Az [[Esc]] törli a keresést.

### Szűrés oszloponként

Az oszlopfejek alatt szűrősor áll: minden oszlopnak saját szűrője van, és több szűrő egyszerre érvényes.

**Szöveges oszlop** (Ügyfél, Típus, IMEI szám…): írd be a keresett részletet. Az alap a *tartalmazza*.

**Szám és pénz** (Sorszám, Bruttó é., Fizetendő…): gépelhető rövidítések:

| Beírás | Jelentés |
|---|---|
| `>5000` | nagyobb, mint 5000 |
| `<=5000` | legfeljebb 5000 |
| `<>0` | nem egyenlő nullával |
| `=12000` | pontosan 12 000 |
| `1000..5000` | 1000 és 5000 között |

**Dátum** (Készült, Határidő, Lezárva…): részlet is elég – a `2026.10` az egész hónapot adja. A `>2026.10.01` az ez utáni, a `<2026.10.01` az ez előtti napokat mutatja.

**Választós oszlop** (Jelzés, Állapot, Felelős, Azonosító, Garancia, Fizetve): kattints a szűrőmezőre, és pipáld ki az értékeket. Az **A kijelöltek kizárása** megfordítja a szűrést, a **Mind** mindent kijelöl.

A mezők jobb szélén lévő kis gomb megnyitja a **szűrési feltétel** panelt a többi feltétellel: *nem tartalmazza, ezzel kezdődik, erre végződik, üres, nem üres*; dátumnál *ma, tegnap, ezen a héten, ebben a hónapban, az utolsó 7 napban, az utolsó 30 napban, ma előtt (lejárt)*. Alul **Alkalmaz** és **Szűrő törlése**.

Egy szűrőt a mezőjében az [[Esc]] töröl. Mindet egyszerre a **Szűrők törlése** gomb.

### Rendezés

- Kattints az oszlop fejlécére: először növekvő, másodszor csökkenő, harmadszor megszűnik a rendezés.
- [[Shift]] + kattintás: másodlagos rendezés. Legfeljebb három oszlop szerint rendezhetsz, a fejlécben szám mutatja a sorrendet.
- Az **Állapot** oszlop az állapotlista sorrendjében rendez, nem ábécé szerint.
- Az ügyfél, telefonszám, cím, gyártó, típus, megnevezés, IMEI, sorozatszám és azonosító szerinti rendezés nagyon sok munkalapnál is gyors (a rendszer az ügyfelek és eszközök sorrendjében halad). Nagyon mély oldalon (több ezredik sor) lassabb lehet.

### Összesítő sor

A rács alján az *összes* találat összege áll, nem csak a látható oldalé: a munkalapok száma (Σ), és a **Bruttó é.**, a **Fizetett**, a **Fizetendő** összege.

## Részletpanel a kijelölt sor alatt

Az Áttekintés rácsa alatt a kijelölt munkalap részletei látszanak, lapfüleken:

| Lapfül | Tartalom |
|---|---|
| **Munkalap** | Állapot, eszköz, ügyfél, felelős, dátumok, fizetés, összegek. Bal oldalt kiemelve a bruttó összeg és a fizetendő. |
| **Eszköz** / **Eszközök (3)** | Az eszköz adatai; több eszköznél mind, egymás alatt. |
| **Ügyfél** | Az ügyfél adatai. |
| **Hibák** | A lap hibasorai. |
| **Szolgáltatások**, **Termékek** | A tételek, összesítve. Több eszköznél Eszköz oszloppal. |
| **RMA / Üzenetek** | Címke, ügyféloldal, üzenetek az ügyféllel. A jelvény az üzenetek számát mutatja; kiemelve, ha van olvasatlan. |
| **Számlák**, **Pénztárbizonylatok** | Ezek a lapfülek jelenleg üresek. A számlákat a [Számlázás](sugo:szamlazas), a pénztártételeket a [Házipénztár](modul:penztar) mutatja. |

Bal oldalt alfülek vannak (**Adatok**, **Megjegyzés**, az eszköznél és az ügyfélnél **Csatolt fájlok**); a *(!)* jel azt mutatja, hogy van rajta tartalom. Fölöttük a **Szerkesztés** gomb megnyitja a lapot, az eszközt vagy az ügyfelet.

A panel alján olvasható, ki és mikor készítette a munkalapot. A magasságát a rács és a panel közötti elválasztó húzásával állítod; az elválasztón a **Részletek elrejtése** gomb el is tünteti (vissza: **Részletek megjelenítése**).

Több eszközös lapnál a **Munkalap** lapfülön eszközönként külön állapotválasztó áll; a váltás azonnal mentődik.

## Lépésről lépésre: állapotváltás a rácsból

1. Keresd meg a munkalap sorát az Áttekintés rácsában.
2. Az **Állapot** oszlopban kattints a színes állapotra.
3. Válaszd ki az új állapotot. Azonnal mentődik, nincs külön Mentés gomb.

Mi történik ilyenkor:

- Ha a lap most lép először számozott állapotba, **munkalapszámot kap**.
- Lezárt állapotnál beíródik a **Lezárva** dátum, és a sor elhalványul.
- A rács és a csempék frissülnek; szűrt nézetből a sor el is tűnhet.
- Ha a váltás nem lehetséges, visszaáll az előző állapot, és a rács kiírja az okot.

> **Figyelem:** Több eszközös lapnál a rácsban a **közös** állapotot látod; mellette például `1/2` jelzi, hány eszköz kész. Ha itt írod át, **minden eszköz** ezt kapja. Egyetlen eszközét a részletpanelen vagy a munkalapon állítsd.

## Jelzés (zászló)

Az Áttekintés rácsának **Jelzés** oszlopában a zászlóra kattintva megjelölheted a munkalapot (**Megjelölés**), újra kattintva leveszed (**Jelzés levétele**). A jelzést a kollégák is látják; az oszlop szűrőjével csak a megjelölt lapokat is kérheted.

## Napi árukiadás a rácsban

A pultos eladások lapjai az Áttekintés rácsában a munkalapok között állnak, ugyanabban a számsorban.

- Az **Azonosító** oszlopban *Napi árukiadás* áll.
- A **Típus** oszlopban az eladott tételek olvashatók.
- Az **Ügyfél** oszlopban a vevő neve; ha nem választottál ügyfelet, itt is *Napi árukiadás* áll.
- Gyártó, IMEI szám és sorozatszám nincs – az eladásnak nincs eszköze.
- Dupla kattintásra az árukiadás lapja nyílik meg.

Csak az eladásokat a **Napi árukiadás** nézet mutatja, nélkülük a **Csak javítás (árukiadás nélkül)** nézet.

## Áttekintés csempéi

A [Beállítások](modul:beallitasok) egyik lapfüle (*Cég és megjelenés* csoport), csak adminisztrátor éri el. Itt adod meg, melyik modul csempéje látszik az Áttekintés tetején, milyen sorrendben és felirattal. Mindenkire érvényes; a lapfül alján **Mentés**.

Minden modulnak egy sora van:

| Oszlop | Mire való |
|---|---|
| **Látszik** | Pipa nélkül a csempe senkinél nem jelenik meg. A menüpont ettől még megmarad. |
| **Sorrend** | A fel és le nyilakkal a sor feljebb vagy lejjebb kerül. |
| **Cím a csempén** | Saját cím, legfeljebb 40 karakter. Üresen a modul neve marad. |
| **Alsó felirat** | A szám alatti szöveg, legfeljebb 60 karakter. Üresen az alapértelmezett marad. |
| **Szám** | Pipa nélkül a csempén nem látszik szám, csak a felirat. |

A táblázat alatt a **Mindent vissza az alapértelmezésre** jelölő: bepipálva és mentve minden csempe visszaáll az eredetire.

> **Fontos:** A fejléc **Csempék elrejtése** gombja más: az *összes* csempét rejti el, és csak a te böngésződben. A Beállítások egyenként, mindenkinél állítja a csempéket.

## Szabályok, amiket a rendszer betart

- Az Áttekintés rácsában javítási munkalapok és napi árukiadások állnak, rendelés nincs.
- A szűrés, a rendezés és az összesítés az összes lapra vonatkozik, nem csak a látható oldalra.
- Javítási lap csak akkor tehető számozott állapotba, ha van ügyfele és eszköze.
- A *Részben elkészült* állapot több eszközös lapnál magától áll be, kézzel nem választható.
- A csak adminisztrátornak szóló modul csempéje a kollégánál nem jelenik meg.

## Ha valami nem megy

- **„A szűrésnek egyetlen munkalap sem felel meg.”** – Túl szűk a szűrés. Kattints a **Szűrők törlése** gombra.
- **Nem találom a most felvett lapot.** – Valószínűleg szűrt nézetben vagy. Válts a **Minden munkalap** nézetre.
- **„A munkalapok nem töltődtek be.”** – Kattints az **Újra** gombra. A *Lejárt a munkamenet* üzenetnél frissítsd az oldalt.
- **Nem látszanak a csempék.** – Kattints a **Csempék megjelenítése** gombra. Ha csak egy hiányzik, a Beállításokban van kikapcsolva.
- **Összekeveredtek az oszlopok.** – **Oszlopok › Alaphelyzet**.

## GYIK

### Hogyan nyitok meg egy munkalapot a rácsból?

Kattints duplán a sorára, vagy jelöld ki és nyomj [[Enter]]-t. A részletpanel **Szerkesztés** gombja is megnyitja.

### Miért halványabb néhány sor?

Azok a lezárt munkalapok (Lezárt, Érvénytelen). Megnyithatók és szerkeszthetők, csak már nem számítanak nyitottnak.

### Miért nincs sorszáma egy sornak?

Mert a lap még nem volt számozott állapotban – jellemzően árajánlat vagy sablon. Amint számozott állapotba teszed, megkapja a következő munkalapszámot.

### Hogyan keresek IMEI-re vagy telefonszámra?

Írd be a rács keresőjébe. Részlet is elég. Az IMEI szám és a Telefonszám oszlopnak látszania kell.

### Hogyan nézem meg, mennyi a kintlévőség?

Válaszd a **Fizetendő** nézetet, és kapcsold be az **Összesítő sort**. A Fizetendő oszlop alatt az összes találat összege áll.

### Mit jelent a „+1” a Típus oszlopban?

A munkalapon több eszköz van: a fő eszköz mellett még egy. Mindet a részletpanel **Eszközök** lapfülén látod.

### Miért tűnt el a sor, amikor átállítottam az állapotát?

Mert szűrt nézetben vagy, és az új állapot már nem felel meg a szűrésnek. A lap megvan: a **Minden munkalap** nézetben megtalálod.

### Miért nem enged a rács számozott állapotba tenni egy lapot?

Mert a lapnak nincs ügyfele vagy eszköze. Nyisd meg a lapot, válaszd ki mindkettőt, és ments.

### Hogyan szűrök egy kolléga munkalapjaira?

A **Felelős** oszlop szűrőjében pipáld ki a nevét.

### Megmarad a szűrésem, ha kilépek?

Ugyanabban a böngészőben igen. Másik gépre saját nézetet ments: az a fiókodhoz tartozik.

### Hogyan rejtem el a csempéket?

A fejléc **Csempék elrejtése** gombjával. Ez csak a te böngésződre szól.

### Hol vannak a rendelések?

A [Rendelések](modul:rendelesek) menüpontban. A rácsba csak a javítási munkalapok és a napi árukiadások kerülnek.

### Átnevezhetem a csempéket?

Igen, adminisztrátorként: **Beállítások › Áttekintés csempéi**, a **Cím a csempén** és az **Alsó felirat** mezőben.

### Hol van az óra és a betűméret csúszkája?

Az óra a felső fejlécben van, minden oldalon. A betűméretet a profilmenüben állítod (jobb felső sarok, a nevedre kattintva).

### Van segítség, ha először látom ezt az oldalt?

Igen. Kérd meg a jobb alsó sarokban lévő asszisztenst: „Vezess végig ezen az oldalon”. Lépésenként megmutatja az oldal részeit.

## Túra: attekintes

- `.sdh-fejlec__gombok` | Gyors gombok | Új munkalap, ügyfél, termék, szolgáltatás, árukiadás vagy rendelés egy kattintással. Az utolsó gomb elrejti a csempéket.
- `[data-sdh-csempek]` | Modulcsempék | Minden menüpont egy csempe a legfontosabb számával. Kattintásra megnyílik a modul; a kiemelt csempén teendő vár.
- `.sdh-racs__eszkoztar` | A rács eszköztára | Lapozás, frissítés, összesítő sor, a szűrők törlése és az oldalméret. Itt látod a találatok számát is.
- `.sdh-racs__gomb--nezet` | Nézetek | Kész szűrések: nyitott, átvételre váró, fizetendő, lejárt határidejű lapok. A saját szűrésedet is ide mentheted.
- `.sdh-racs__kereso` | Keresés | A beírt szöveget a rács minden látható oszlopában keresi: névben, telefonszámban, IMEI-ben, típusban.
- `.sdh-racs__fejsor` | Oszlopfejek | Kattintásra rendez, a széle húzásával szélesíthető, a fejléc húzásával áthelyezhető.
- `.sdh-racs__szurosor` | Szűrősor | Minden oszlopnak saját szűrője van: írd be az értéket, vagy válassz a listából.
- `.sdh-racs__tabla tbody` | Munkalapok | Egy sor egy munkalap vagy árukiadás. Kattintás kijelöli, dupla kattintás megnyitja; az állapot a sorban is állítható.
- `.sdh-racs__elvalaszto` | Elválasztó | Húzással állítod a részletek magasságát; a gombjával el is rejtheted őket.
- `.sdh-reszlet` | Részletek | A kijelölt munkalap adatai lapfüleken: munkalap, eszköz, ügyfél, hibák, tételek, üzenetek.
