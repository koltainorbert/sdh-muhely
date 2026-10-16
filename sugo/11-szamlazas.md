---
kulcs: szamlazas
cim: Számlázás
csoport: Pénz
modul:
szakaszok: szamlazas
kulcsszavak: számla, számlázás, Számlázz.hu, Számla Agent, agent kulcs, számla kiállítása, előnézet, számla-előkészítő, díjbekérő, elszámolás, átadási bizonylat, PDF, vevő, céges számla, adószám, cég keresése, bevizsgálási díj, adattörlő kód, e-számla, előtag, számlatömb, tétel megjegyzés, eszközönkénti számla, bevizsgálási díj gomb, díj a számlára, bevizsgálási díj felvétele
osszefoglalo: Számla a munkalapról a Számlázz.hu-n keresztül, és helyben készülő, számlának nem minősülő előkészítő PDF.
---

## Mire való

A munkalap, a napi árukiadás és a rendelés ablakának alján két gomb tartozik a számlázáshoz. A kettő nagyon különböző dolgot csinál.

| Gomb | Mit készít | Számla? |
|---|---|---|
| **Számlázz.hu számla** | Valódi számlát a saját Számlázz.hu-fiókodban, a Számla Agenten keresztül. | Igen. |
| **Számla-előkészítő (PDF)** | Helyben készülő, letölthető és nyomtatható PDF-et a tételekből. | **Nem.** |

A valódi számlát mindig a Számlázz.hu állítja ki; a rendszer csak összeállítja és elküldi neki az adatokat.

**Számla soha nem készül magától.** A gomb csak a számla ablakát nyitja meg. Ott látod a vevőt és a tételeket, mindent átírhatsz, megnézheted az előnézetet – számla pedig kizárólag akkor készül, amikor az ablakban a **Számla kiállítása** gombra kattintasz.

A számla-előkészítő az a papír, amit a kész termék mellé teszel. Rá is van írva: *„Ez a bizonylat nem minősül számlának – a számlát külön állítjuk ki."* Internet és Számlázz.hu-fiók nélkül is elkészül.

## Hol találod

A két gomb a **már elmentett, sorszámot kapott** lap ablakának alján áll, a **Fizetés** gomb mellett:

- a munkalap ablakában,
- a napi árukiadás ablakában,
- a rendelés ablakában.

Új, még nem rögzített lapon és sorszám nélküli (például árajánlat állapotú) munkalapon a gombok nem jelennek meg.

A gombok mellett kis címkéken látszik a laphoz már kiállított legutóbbi három számla száma. Egy címkére kattintva a számla PDF-je új lapon nyílik meg. Ha háromnál több számla van, a többit egy **+** jelű címke jelzi.

A számlázásnak nincs saját menüpontja. A beállításai a Beállítások **Számlázás** lapfülén vannak.

## Lépésről lépésre: számla kiállítása a munkalapról

1. Nyisd meg a munkalapot, és az ablak alján kattints a **Számlázz.hu számla** gombra. A rendszer előbb elmenti a munkalapot, hogy a számlára a friss tételek kerüljenek.
2. Megnyílik a számla ablaka. A címsorban látod a munkalap számát és azt, melyik számlatömbbe kerül a számla.
3. Ellenőrizd a **Vevő** dobozt: név, adószám, irányítószám, település, utca és házszám, e-mail. Az adatok az ügyfél adatlapjáról jönnek.
4. A **Fizetés** dobozban ellenőrizd a **Fizetési mód**, a **Teljesítés** és a **Fiz. határidő** mezőt, valamint a **Fizetve** pipát.
5. A tételek táblájában hagyd bepipálva, ami a számlára kerül. Amit nem kérsz rá, annál vedd ki a pipát.
6. Nézd meg alul a **Végösszeg** sort, és ha kell, írd át a **Megjegyzés** mezőt.
7. Ha biztos akarsz lenni, kattints az **Előnézet (PDF)** gombra. Ez megmutatja, hogyan fog kinézni – számla nem készül.
8. Kattints a **Számla kiállítása** gombra.

Ha a számla elkészült, a munkalap ablaka újratöltődik, és a tetején megjelenik: **Elkészült a számla**, a számla számával, az összeggel és a **PDF megnyitása** hivatkozással.

> **Fontos:** A kiállított számla végleges. A rendszerben nem módosítható és nem törölhető, és sztornó művelet sincs benne: a javítás a Számlázz.hu-fiókodban történik. Kiállítás előtt ezért mindig nézd át az ablakot.

> **Tipp:** A számla ablakában az [[Enter]] egy szövegmezőből soha nem állít ki számlát. A névnél és az adószámnál keresést indít, máshol nem csinál semmit.

## Lépésről lépésre: a vevő átírása és céges számla

A számla nem csak az ügyfél nevére szólhat. A **Vevő** doboz minden mezője átírható, és ettől az ügyfél adatlapja nem változik.

**Cégadatok az adószámból**

1. Írd be az **Adószám** mezőbe a cég adószámát. Elég az első nyolc számjegy.
2. Ha nyolc vagy tizenegy számjegyet írtál be, a keresés magától elindul. Egyébként kattints a **Cég keresése** gombra.
3. A rendszer a Számlázz.hu-n keresztül lekéri a cég nevét és címét a NAV nyilvántartásából, és kitölti a mezőket. A doboz alján olvasható, honnan jött az adat.

Ha a lekérdezés nem sikerül, de a saját ügyfeleid között van ilyen adószámú, az ő adatai töltődnek ki. Ha az adószám vége hiányzik a válaszból, kézzel kell beírnod.

**Vevő a listáról**

A **Név** mező melletti **Listáról…** gomb az ügyfeleid és a korábbi számlák vevői közül enged választani. Aki egyszer céges számlát kért, az legközelebb innen egy kattintással kiválasztható.

**Kézzel**

Egyszerűen írd át a mezőket.

Amit a rendszer a vevőnél figyel:

- Valódi számlához kötelező a név, az irányítószám, a település és az utca, házszám. Amíg valamelyik hiányzik, a **Számla kiállítása** és az **Előnézet (PDF)** gomb nem kattintható.
- Az adószámot ebben az alakban várja: `12345678-1-12` (tizenegy számjegy). Magánszemélynél a mező üresen marad.
- Ha a számla más névre szól, mint a munkalap ügyfele, az ügyfél telefonszáma és postázási címe nem kerül rá.
- Ha az ügyfélnek eddig nem volt e-mail-címe, és a saját nevére szóló számlánál most beírod, a cím az adatlapjára is elmentődik.

## Lépésről lépésre: tételek hozzáadása, kihagyása és megjegyzése

A számla ablakában a munkalap minden számlázható tétele megjelenik: elöl a bevizsgálási díj, utána a szolgáltatások, végül a termékek. Minden tétel mellett címke mutatja a fajtáját.

**Tétel kihagyása.** Vedd ki a pipát a sor elejéről. A tétel a munkalapon megmarad, csak erre a számlára nem kerül rá.

**Új tétel felvétele.** A tábla alján ezek a gombok állnak:

- **Termék…** – a terméklistából keresel és választasz.
- **Szolgáltatás…** – a szolgáltatások közül választasz.
- **Bevizsgálási díj…** – a díjlistából (a Szolgáltatások között bevizsgálási díjként jelöltek közül) választasz; a sor a **készülék nevével** kerül a számlára, a Számlázás beállításaiban megadott minta szerint (pl. „Samsung SM-A175B mobiltelefon bevizsgálási díj”), a díj árával. Több eszközös lapon az ablak tetején választott eszközé, ennek híján a fő eszközé. Ha nincs a listában a kellő díj, a **kézzel** gombbal üres díjsort kapsz a készülék nevével, és az árat te írod be.
- **Kézi tétel** – üres sort kapsz, a megnevezést és az árat te írod be.
- **★ Gyakori tételek…** – a leggyakoribb tételek listája.

Az új sorban megadható a megnevezés, a mennyiség, a mennyiségi egység, a **bruttó** egységár és az áfakulcs; a nettót a rendszer számolja, és a bruttó ár mellett, ugyanabban a sorban mutatja („nettó …”). A tételsor minden cellája egy vonalban áll. A megnevezés melletti kis címkére kattintva a tétel fajtája váltható (szolgáltatás vagy termék).

**Új tétel törlése.** Az itt felvett sor elején **×** gomb áll: ezzel törölheted. A munkalapról érkezett tételt nem törölni kell, hanem a pipát kivenni.

**Megjegyzés tételenként.** A megnevezés sorában, a fajta (Szolgáltatás / Termék) mellett álló **+ Megjegyzés** pill egy mezőt nyit a tétel alatt – a kézi tételnél is a név mellett van, nem alatta, így nem tolja el a sort. Amit ide írsz (például IMEI, garancia, gyári szám), az a számlán a tétel alatt jelenik meg.

> **Fontos:** A számla ablakában felvett új tétel a számla kiállításakor a munkalapra is rákerül, így a munkalap végösszege követi a számlát. Ha csak előnézetet kérsz, vagy bezárod az ablakot, a munkalap nem változik.

A már kiszámlázott tétel megjegyzi a számla számát. Ha később újabb számlát készítesz ugyanarról a lapról, ez a tétel halványan látszik, a számla számával, és alapból nincs bepipálva.

## A számla tételei magától vagy kézzel (fogaskerék)

A fogaskerékkel a munkalap tételei el is tüntethetők az ablakból (**A munkalap tételei a listában** ki): ekkor csak a kézzel felvett tétel kerül a számlára. A **★ Gyakori tételek…** gomb a Leggyakoribb Számla tételek listáját nyitja (Telefon / TV / Számítógép szerviz szolgáltatás, Számítógép alaplapi javítás…): kattintásra kézi tételként felkerül, és ugyanott szerkeszthető. A Kézi tétel megnevezésénél gépelés közben a korábban beírt tételnevek is felkínálkoznak. Részletesen: [Űrlapok beállítása](sugo:urlapok).


A számla ablakának jobb felső sarkában álló **fogaskerékkel** (adminisztrátor) állítod, hogy a munkalap tételei **magától kipipálva** nyíljanak-e. Kikapcsolva egy tétel sincs kipipálva: te pipálod be, ami kell, vagy **Kézi tétel**lel írod – így a kollégák nem tévesztenek a pipálgatásnál. Ugyanitt kapcsolható a tétel-megjegyzés (eszköz neve, IMEI) és a **Megjegyzés** mező automatikus kitöltése. A Számlázz.hu számla és a számla-előkészítő **külön** állítható, és a beállítás frissítés után is megmarad.

A tétellista fölötti **A munkalap tételei a számlán** kapcsoló egy kattintással mindet be- vagy kipipálja – csak abban az ablakban, az alapállás marad. Részletesen: [Űrlapok beállítása](sugo:urlapok).

## Lépésről lépésre: bevizsgálási díj és több eszköz a számlán

**Bevizsgálási díj külön tételként**

Ha a munkalapon be van pipálva a **Bevizsgálási díj**, az előleg összege külön tételsorként áll a munkalapon és a számlán is. A neve a készülék típusából áll össze, például: „Minta X1 mobiltelefon bevizsgálási díj".

- Átvételkor, amíg nincs javítás, a díj sora maga a számlázható tétel: erről kap számlát az ügyfél (eszközönként külön is).
- Ha az ügyfél kéri a javítást, a díj levonódik. A **záró számlán** ilyenkor sem a díj, sem a levonás nem szerepel – a díjat már kifizette, és számlát is kapott róla. A számlára csak a javítás tétele kerül, a saját nevén, a még fizetendő összeggel. Például 10 000 Ft-os akkumulátor és 8 000 Ft díj esetén a záró számlán: „Huawei P30 Lite akkumulátor – 2 000 Ft". Ha az eszköznek több tétele van, a díj a legnagyobb értékűből vonódik le; ami így 0 Ft-ra fogy, kimarad. A munkalapon a tételek eredeti ára megmarad.
- Ha nem kéri a javítást, a díj munkadíjként megmarad.

Így a bevizsgálási díj az átvételkor, a javítás pedig a végén külön számlázható, és a két számla együtt adja ki a teljes összeget. A díj és a levonás megnevezése a Beállítások **Számlázás** lapfülén írható át.

**Eszközönkénti számla**

Ha a munkalapon több eszköz van, a számla ablakában megjelenik a **Mi kerül erre a számlára** sor:

- **Minden eszköz együtt** – egy közös számla a lap minden eszközéről.
- Egy-egy eszköz neve – csak annak az eszköznek a tételei maradnak bepipálva.

Eszközt választva a megjegyzésbe is csak az az egy eszköz kerül. A pipák utána kézzel is átállíthatók: a számlára az kerül, ami be van pipálva. Több eszköznél a tételek megjegyzése előre ki van töltve azzal, melyik készüléké (név és IMEI vagy gyári szám); ez átírható vagy kitörölhető.

## Lépésről lépésre: adattörlő kód, e-mail és a kész számla PDF-je

**Adattörlő kód.** Ha a Beállításokban be van kapcsolva, a tételek táblájában megjelenik az **Adattörlő kód** oszlop, soronként egy kapcsolóval. Amelyik tételnél bekapcsolod, ahhoz a Számlázz.hu rendel kódot – darabonként egyet. Ez csak akkor működik, ha a Számlázz.hu-fiókodban is be van kapcsolva. A számla-előkészítőnél ez az oszlop nincs.

**E-mail az ügyfélnek.** Ha a **Fizetés** dobozban bepipálod, a Számlázz.hu elküldi a számlát a vevő e-mail-címére. Ha nincs megadva cím, a rendszer a **Vevő** dobozban kéri, és addig nem enged kiállítani.

**A kész számla PDF-je.** A kiállított számla PDF-jét a rendszer eltárolja. Megnyithatod:

- kiállítás után a munkalap tetején megjelenő **PDF megnyitása** hivatkozással;
- később a munkalap alján, a gombok melletti számlaszám-címkére kattintva;
- a számla ablakában az **Ehhez a munkalaphoz már készült számla** sorból.

A PDF új lapon nyílik meg, onnan nyomtatható és menthető.

**Pénztár.** A számla kiállítása után a munkalap pénztártételei maguktól megkapják a számla számát. Ha a pénz még nincs a pénztárban, a rendszer – a pénztár beállításától függően – megkérdezi, bekerüljön-e. Erről a [Házipénztár](sugo:penztar) fejezet ír bővebben.

## Lépésről lépésre: számla-előkészítő PDF készítése

1. A lap ablakának alján kattints a **Számla-előkészítő (PDF)** gombra. (Ha a Beállításokban más címet választottatok, a gomb felirata az lesz: **Díjbekérő**, **Elszámolás** vagy **Átadási bizonylat**.)
2. Megnyílik ugyanaz az ablak, mint a számlánál, de a címsorban a nyomtatvány saját sorszáma áll.
3. Ellenőrizd a vevőt és a tételeket. Itt csak a vevő neve kötelező; a cím hiányozhat.
4. Kattints a **PDF megnyitása** gombra. A PDF új lapon nyílik meg, a gomb mellett pedig megjelenik egy **Letöltés** hivatkozás.

Miben más, mint a számla:

- **Nem számla.** A PDF-en ez kétszer is szerepel: a végösszeg alatt és a láblécben.
- A sorszáma saját előtagot kap, előtag–év–munkalapszám alakban. Több eszközös lapon az egy eszközről készült nyomtatvány sorszáma az eszköz sorszámával végződik.
- Helyben készül, a Számlázz.hu-hoz nem megy semmi. Internet nélkül is működik.
- A **Fizetve** és az **E-mail az ügyfélnek** pipa itt nincs.
- Az ablakban felvett új tétel csak a nyomtatványra kerül. Ha a munkalapon is szerepelnie kell, ott vedd fel a **Termékek** vagy a **Szolgáltatások** lapfülön.
- A rendszer nem tárolja el: bármikor újra elkészíthető.

A PDF tetején a logó, a kiállító és a bank adatai állnak, alatta a vevő, a fizetési adatok, a tételek táblája és a fizetendő összeg, egész forintra.

## A Beállítások „Számlázás" lapfüle

A Beállítások **Pénz és eladás** csoportjában találod a **Számlázás** lapfület. A Beállításokat csak adminisztrátor nyithatja meg.

**A Számlázz.hu-kapcsolat**

- **Számla Agent kulcs** – a Számlázz.hu-fiókod kulcsa; a fiók beállításai között, a Számla Agent kulcsoknál találod. A beírt kulcs mentés után nem jelenik meg újra a mezőben, és az adatbázisban (így a mentésfájlban is) **titkosítva** áll. Csak akkor írj bele, ha cserélni akarod.
- **Kapcsolat ellenőrzése** – mentett kulcsnál megnézi, elfogadja-e a Számlázz.hu a kulcsot. Számla nem készül.
- **Kulcs törlése** – bepipálva és mentve a kulcs törlődik.
- **E-számla (elektronikus)** – kikapcsolva papír alapú, nyomtatandó számla készül.
- **Előtag – „Számlázz.hu számla" gomb** – melyik számlatömbbe kerüljön a számla. Üresen a fiók alapértelmezett számlatömbje. Csak olyan előtag használható, amely a Számlázz.hu-n már fel van véve.
- **Alap fizetési mód** – akkor érvényes, ha a munkalapon nincs megadva fizetési mód.
- **Fizetési határidő (nap)** – átutalásnál ennyi nappal a számla napja utánra kerül a határidő. Készpénznél és kártyánál a határidő a számla napja.
- Jelölő arról, hogy a számla ablakában alapból be legyen-e pipálva az **E-mail az ügyfélnek** – ha bekapcsolod, a pipa előre be lesz jelölve, amennyiben a vevőnek van e-mail-címe.
- **Adattörlő kód használata** – ezzel jelenik meg a számla ablakában a tételenkénti kapcsoló.
- **Megjegyzés a számlán** – a számla megjegyzésének alapszövege. Helyőrzők: `{munkalap}`, `{eszkoz}`, `{imei}`. A számla ablakában átírható.
- **A bevizsgálási díj megnevezése** – helyőrzők: `{eszkoz}` (gyártó és típus), `{fajta}` (például mobiltelefon), `{dij}` (a díjlistából választott díj neve).
- **A díj levonásának (mínusz tétel) megnevezése** – ugyanazokkal a helyőrzőkkel.

**Helyi nyomtatvány (API nélkül)**

- **A nyomtatvány címe** – **Számla-előkészítő**, **Díjbekérő**, **Elszámolás** vagy **Átadási bizonylat**. Ez lesz a gomb felirata is a munkalapon. „Számla" szándékosan nincs a választék között.
- **Sorszám-előtag** – a nyomtatvány sorszámának eleje. Nem egyezhet a számlatömb előtagjával, hogy a nyomtatvány ne legyen összetéveszthető a számlákkal. Ha mégis egyezne, a rendszer mentéskor módosítja.
- **Kiállító neve**, **Adószám**, **Cím – irányítószám, település**, **Cím – utca, házszám** – a nyomtatvány fejlécébe kerülnek.
- **Bank neve**, **Bankszámlaszám** – szintén a fejlécbe.
- **Levelezési cím (lábléc)**, **Telefon (lábléc)** – a nyomtatvány aljára.
- **Logó a nyomtatványon** – üresen a rendszerrel érkező logó. Saját logóhoz töltsd fel a képet (PNG vagy JPG) a Médiatárba, és másold ide a fájl címét.

> **Fontos:** A kiállító itt megadott adatai csak a helyi nyomtatványra kerülnek. A valódi számlán a kiállító adatai a Számlázz.hu-fiókodból jönnek.

## Szabályok, amiket a rendszer betart

- **Számla csak gombnyomásra készül.** A lap mentése, a fizetés rögzítése vagy a lezárás soha nem állít ki számlát.
- **Számla csak sorszámot kapott laphoz készíthető.**
- **A számla gomb előbb elmenti a lapot.** Ha a mentés nem sikerül, a számla ablaka nem nyílik meg.
- **Az előnézet nem számla.** Az előnézethez nem készül számlaszám, és e-mail sem megy ki.
- **Legalább egy tétel kell.** Tétel nélkül sem számla, sem nyomtatvány nem készül.
- **A munkalapon az ár bruttó, a számla nettó egységárból számol.** A nettó egységár két tizedesre kerekül, ezért a számla bruttója néhány fillérrel eltérhet a munkalapétól.
- **A kedvezményes tétel** megnevezése végére a számlán odakerül a kedvezmény mértéke.
- **A fizetési határidő nem lehet korábbi a mai napnál.** Ha korábbit adsz meg, a mai nap kerül a számlára. A számla kelte mindig a mai nap.
- **A számla-előkészítő nem számla,** és a sorszáma nem egyezhet a számlák előtagjával.

## Ha valami nem megy

- **„Még nincs megadva a Számla Agent kulcs."** A Beállítások **Számlázás** lapfülén add meg a kulcsot. Addig a **Számla kiállítása** és az **Előnézet (PDF)** gomb nem kattintható. A számla-előkészítő kulcs nélkül is működik.
- **A Számlázz.hu nem fogadta el a kulcsot.** Ellenőrizd a kulcsot a Beállításokban, és használd a **Kapcsolat ellenőrzése** gombot.
- **Az előtag nincs felvéve.** A beállított előtagot előbb a Számlázz.hu-fiókodban kell felvenni.
- **Nincs engedélyezve az e-számla a fiókban.** Kapcsold ki az **E-számla** jelölőt a Beállításokban, vagy engedélyezd a Számlázz.hu-n.
- **A Számlázz.hu nem érhető el.** Ilyenkor számla nem készült. Próbáld újra később; addig a számla-előkészítő használható.
- **Nem jött vissza számlaszám.** Mielőtt újra próbálod, nézd meg a Számlázz.hu-fiókodban, elkészült-e a számla.
- **A számla PDF-je nem nyílik meg.** A rendszer megpróbálja újra letölteni. Ha ez sem sikerül, a számlát a Számlázz.hu-fiókodban a számlaszám alapján megtalálod.
- **Nem látom a számla gombokat.** A lap még nincs elmentve, vagy nincs sorszáma.
- **A hibaüzenet végén hibakód áll.** Ez a Számlázz.hu saját kódja; a rendszer a szöveget is kiírja mellé.

## GYIK

### Készülhet számla úgy, hogy nem akartam?

Nem. A munkalapon lévő gomb csak a számla ablakát nyitja meg. Számla kizárólag az ablak **Számla kiállítása** gombjára készül. A mentés, a fizetés és a lezárás nem számláz.

### Mi a különbség a „Számlázz.hu számla" és a „Számla-előkészítő (PDF)" között?

Az első valódi számlát állít ki a Számlázz.hu-fiókodban. A második helyben készít egy nyomtatható PDF-et, amely nem számla – ez rá is van írva.

### Hogyan nézem meg a számlát, mielőtt kiállítom?

A számla ablakában kattints az **Előnézet (PDF)** gombra. A PDF új lapon nyílik meg. Ettől számla nem készül.

### Hogyan állítok ki céges számlát magánszemély ügyfélnek?

A számla ablakában írd be a cég adószámát az **Adószám** mezőbe: a név és a cím kitöltődik. Az ügyfél adatlapja ettől nem változik, csak ez a számla szól a cég nevére.

### Miért nem kattintható a „Számla kiállítása" gomb?

Három oka lehet: nincs megadva a Számla Agent kulcs, a vevő kötelező adatai hiányosak (név, irányítószám, település, utca és házszám), vagy egyetlen tétel sincs bepipálva.

### Hogyan hagyok ki egy tételt a számláról?

Vedd ki a pipát a tétel sorának elejéről. A tétel a munkalapon megmarad.

### Felvehetek olyan tételt a számlára, ami nincs a munkalapon?

Igen, a **Termék…**, a **Szolgáltatás…** vagy a **Kézi tétel** gombbal. A számla kiállításakor ez a tétel a munkalapra is rákerül.

### Hová írjam az IMEI-t vagy a garanciát a számlán?

A tétel megnevezése melletti **+ Megjegyzés** pillel nyiss mezőt, és írd oda. A számlán a tétel alatt jelenik meg. Az egész számlára vonatkozó szöveget az ablak alján a **Megjegyzés** mezőbe írd.

### Hogyan kerül a bevizsgálási díj a számlára?

Átvételkor a díj külön tételként szerepel, a készülék típusával a nevében – erről kap számlát az ügyfél. Javítás esetén a záró számlán már nem szerepel sem a díj, sem a levonása: csak a javítás tétele, a saját nevén, a még fizetendő összeggel (a teljes ár mínusz a már kifizetett díj).

### Számlázhatom külön a bevizsgálási díjat és később a javítást?

Igen, és ez a megszokott menet. Átvételkor a számlán csak a díj áll. A záró számlán a díj és a levonása már meg sem jelenik: a javítás tétele a még fizetendő összeggel kerül rá (10 000 Ft-os termék, 8 000 Ft díj → 2 000 Ft).

### Több eszköz van a munkalapon. Kaphat mindegyik külön számlát?

Igen. A számla ablakában a **Mi kerül erre a számlára** sorban válaszd ki az eszközt: csak az ő tételei maradnak bepipálva. Ismételd meg eszközönként.

### Mi az adattörlő kód, és miért nem látom a kapcsolóját?

Az adattörlő kódot a Számlázz.hu rendeli a tételhez. A kapcsoló csak akkor látszik, ha a Beállítások **Számlázás** lapfülén be van kapcsolva az **Adattörlő kód használata**.

### Elküldi a rendszer e-mailben a számlát?

Ha a számla ablakában bepipálod az **E-mail az ügyfélnek** sort, a Számlázz.hu küldi el a vevő e-mail-címére. A levelet nem ez a rendszer küldi.

### Hol találom meg később a számla PDF-jét?

A munkalap ablakának alján, a számla gombok melletti számlaszám-címkére kattintva. A PDF új lapon nyílik meg.

### Elrontottam a számlát. Javíthatom a rendszerben?

Nem. A kiállított számla végleges, a rendszerben nem módosítható és nem törölhető. A javítás a Számlázz.hu-fiókodban történik.

### Működik a számlázás internet nélkül?

A valódi számla nem, mert azt a Számlázz.hu állítja ki. A számla-előkészítő PDF igen: helyben készül.

### Bekerül a számlaszám a pénztárba?

Igen. A számla kiállítása után a munkalap számla nélküli pénztártételei maguktól megkapják a számla számát.

### Átnevezhetem a „Számla-előkészítő" gombot?

Igen, a Beállítások **Számlázás** lapfülén, **A nyomtatvány címe** mezőben. Négy cím közül választhatsz; „Számla" nincs köztük.
