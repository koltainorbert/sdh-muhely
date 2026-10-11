---
kulcs: ugyfelek
cim: Ügyfelek
csoport: Napi munka
modul: ugyfelek
szakaszok:
kulcsszavak: ügyfél, vevő, partner, cég, magánszemély, közület, adószám, cím, irányítószám, település, telefon, e-mail, ügyfélszám, kapcsolattartó, inaktív, ügyfélválasztó, másolás, csatolt fájl, kedvezmény
osszefoglalo: Az Ügyfelek menüpontban magánszemélyeket és cégeket tartasz nyilván; minden munkalap, rendelés és eszköz egy ügyfélhez tartozik.
---

## Mire való

Az **Ügyfelek** menüpont a rendszer ügyféltörzse. Itt van mindenki, aki valaha készüléket adott le, rendelt vagy vásárolt: magánszemélyek, cégek és közületek egy listában.

Az ügyfél a munka kiindulópontja. A munkalap mindig egy ügyfélre hivatkozik, az eszköz mindig egy ügyfélé, a rendelés mindig ügyfélhez kötött. Ezért az ügyfelet elég egyszer felvenni: utána névre, telefonszámra vagy ügyfélszámra gépelve bárhol előhívod.

Az ügyfél adatlapján egy helyen látod az elérhetőségeit, a címeit, a megjegyzést, a csatolt fájlokat, és azt is, milyen munkalapjai, rendelései és vásárlásai voltak.

## Hol találod

- Az oldalmenüben az **Ügyfelek** pontra kattintva: [Ügyfelek megnyitása](modul:ugyfelek).
- Az Áttekintésen az **Új ügyfél** gombbal rögtön felvehetsz egyet.
- A munkalapon, a napi árukiadáson és a rendelésen az **Ügyfél** mező mellett: pluszjel az új ügyfélhez, ceruza a kiválasztott ügyfél szerkesztéséhez.
- Az Eszközök listában az ügyfél nevére kattintva az adatlapja nyílik meg.

Az ügyfél adatlapja mindig felugró ablakban nyílik, így a lista és a keresés a háttérben megmarad.

## Lépésről lépésre: ügyfél keresése a listában

Az Ügyfelek menüpont listája névsorban mutatja az aktív ügyfeleket, oldalanként 25-öt.

1. Kattints a keresőmezőbe. A felirata: `Név, telefon, e-mail, ügyfélszám, cím…`
2. Írd be, amit tudsz: a név egy részét, a telefonszám néhány jegyét, az e-mail-címet, az ügyfélszámot, a települést, az utcát vagy az irányítószámot.
3. Nyomd meg a **Keresés** gombot vagy az [[Enter]] billentyűt.
4. A kereső mellett látod, hány találat van.
5. A sorban a névre vagy a **Megnyit** gombra kattintva megnyílik az adatlap.

A **Szűrő törlése** gomb visszaadja a teljes listát. Ha sok az ügyfél, a lista alján számozott lapozó áll.

> **Tipp:** A telefonszámból elég a közepe vagy a vége. A `123 45` beírására azt is megtalálod, akinek a száma +36 30 123 4567 – feltéve, hogy ugyanúgy tagolva írták be.

## Lépésről lépésre: új ügyfél felvétele

1. Az Ügyfelek menüpontban kattints az **Új ügyfél** gombra. Felugrik az *Új ügyfél* ablak.
2. Írd be a nevet a felső mezőbe. Cégnél a cég nevét, magánszemélynél a teljes nevet. **Csak a név kötelező**, minden mást később is pótolhatsz.
3. Add meg a címet egy sorban: **Isz.**, **Település**, **Utca, házszám**, **Ország**. Az ország alapból Magyarország.
4. A **Státusz** sorban válaszd ki, kiről van szó: **Magánszemély**, **Cég** vagy **Közület**. Az alap a Magánszemély.
5. Cégnél töltsd ki az **Adószám** mezőt, és írd be a **Kapcsolattartó** nevét.
6. Írd be a **Telefon**, a **Telefon 2.** és az **E-mail** mezőt.
7. Ha kell, adj meg **Kategória** szöveget (például viszonteladó) és **Kedvezmény** százalékot.
8. Kattints az **Ügyfél létrehozása** gombra.

Mentés után visszakerülsz a listára, és megjelenik: „Az ügyfél létrejött."

> **Példa:** Név: Minta Márta. Isz.: beírod a négy számjegyet, a település magától megjelenik. Utca, házszám: Példa utca 1. Telefon: +36 30 123 4567. Státusz: Magánszemély.

## Lépésről lépésre: cím megadása irányítószámmal

Az ügyfél adatlapján az **Isz.** és a **Település** mező kitölti egymást. A magyar irányítószám-lista a rendszerben van, nem kell hozzá internet.

1. Írd be a négyjegyű irányítószámot az **Isz.** mezőbe.
2. Ha a kódhoz egy település tartozik, a **Település** rögtön kitöltődik, és a kurzor az utca mezőre ugrik.
3. Ha több település vagy településrész osztozik a kódon, lista nyílik. Válassz a [[↑]] [[↓]] nyilakkal és az [[Enter]] billentyűvel, vagy kattintással.
4. Fordítva is megy: kezdd el írni a település nevét, és a listából válaszd ki. Ha a településnek több irányítószáma van, előbb a nevet választod, utána a kódot.
5. Írd be az utcát és a házszámot.

Tudnivalók:

- Ékezet nélkül is megtalálja a települést. Amikor kilépsz a mezőből, a hivatalos írásmód kerül bele.
- A településrész kötőjellel kerül a mezőbe, ahogy postai címben írják.
- Budapestnél a kerület csak a listában látszik. A mezőbe „Budapest" kerül, a kerületet az irányítószám hordozza.
- Az [[Esc]] csak a listát zárja be, az ablakot nem.

## Lépésről lépésre: levelezési, szállítási cím és telephely

Az ügyfél adatlapjának tetején négy lapfül van: **Központi cím**, **Levelezési cím**, **Szállítási cím**, **Telephely**.

1. Kattints a kívánt lapfülre.
2. Alapból be van pipálva a **Megegyezik a központi címmel** jelölő. Amíg így marad, nincs külön teendőd.
3. Ha a cím eltér, vedd ki a pipát, és töltsd ki az irányítószámot, a települést és az utcát.
4. A **Telephely** lapfülön a telephely nevét is megadhatod (nem kötelező).
5. Mentsd az ügyfelet.

A központi cím a számlázási cím is. Ha egy másodlagos cím megegyezik vele, a rendszer nem tárol másolatot – így a két cím később sem csúszhat szét.

## Lépésről lépésre: ügyfél szerkesztése a munkalapról

A betöltött ügyfelet a munkalapról, a napi árukiadásról és a rendelésről is szerkesztheted. Nem kell kilépned a lapból.

1. Nyisd meg a munkalapot (vagy az árukiadást, a rendelést).
2. Az **Ügyfél** mező mellett kattints a ceruzára. A ceruza csak akkor kattintható, ha már van kiválasztott ügyfél.
3. Ugyanide jutsz az ügyfél kártyáján a **Szerkesztés** gombbal is.
4. Az ügyfél ablaka a lap fölött nyílik meg. Javítsd, amit kell.
5. Kattints a **Mentés** gombra.

Az ügyfél ablaka bezárul, a lap nyitva marad. A név és a kártya adatai helyben frissülnek, a lapon beírt, még el nem mentett munkád nem vész el.

## Lépésről lépésre: csatolt fájl az ügyfélhez

1. Nyisd meg az ügyfél adatlapját.
2. Az alsó lapfülek közül válaszd a **Csatolt fájlok** fület.
3. Húzd a fájlokat a „Húzza ide a csatolandó fájlokat" területre, vagy kattints a **Fájl hozzáadása** gombra.
4. Kattints a **Mentés** gombra. A fájlok csak ekkor kerülnek fel.

Meglévő fájlt az **×** gombbal jelölsz törlésre; ez is mentéskor történik meg. A lefelé nyíl letölti a fájlt. A kép és a PDF kattintásra új lapon nyílik meg, a többi letöltődik.

A lapfülön szám jelzi, hány fájl van csatolva. A fájlokat három nézetben láthatod: **Ikon**, **Lista**, **Tömör**. A választott nézetet a géped megjegyzi.

## Mezők és gombok

### Az ügyféllista oszlopai

| Oszlop | Mit mutat |
|---|---|
| Név | Az ügyfél neve; alatta halványan a kapcsolattartó |
| Típus | Magánszemély, Cég vagy Közület |
| Telefon | Az elsődleges telefonszám |
| E-mail | Az e-mail-cím (keskeny képernyőn elrejtve) |
| Cím | A teljes cím: irányítószám, település, utca |
| Ügyfélszám | Az ügyfél azonosítója |

### Gombok a lista fölött

- **Új ügyfél** – új ügyfél felvétele felugró ablakban.
- **Keresés** – a beírt szöveg keresése.
- **Szűrő törlése** – csak keresés után látszik; visszaadja a teljes listát.
- **Inaktívak** – az inaktívra állított ügyfelek listája. Ott a gomb felirata **Aktív ügyfelek**, azzal lépsz vissza.

### Az adatlap mezői

- **Név** – kötelező.
- **Isz.**, **Település**, **Utca, házszám**, **Ország** – a központi cím.
- **Kategória** – szabad szöveg, például viszonteladó vagy szerződéses partner.
- **Ügyfélszám** – üresen hagyva mentéskor a rendszer ad egyet.
- **Adószám** – cégnél és közületnél.
- **Kedvezmény** – százalék, 0 és 100 között.
- **Státusz** – Magánszemély, Cég, Közület.
- **Telefon**, **Telefon 2.**, **E-mail**, **Kapcsolattartó**.

### Az adatlap alsó lapfülei

- **Megjegyzés** – szabad szöveg az ügyfélről.
- **Csatolt fájlok** – fotó, PDF, számla, nyilatkozat.
- **Munkalapok**, **Rendelések**, **Vásárlások** – csak már elmentett ügyfélnél. A fülön a darabszám áll. Felül összesítő sor: hány darab, mennyi az összeg, és van-e még fizetendő. Egy sorra kattintva a lap az ügyfél ablaka fölött nyílik meg. Görgetés helyett négy soronként lapozol.

### Az adatlap láblécének gombjai

- **Ügyfél létrehozása** (új ügyfélnél) vagy **Mentés**.
- **Mégsem** – bezárja az ablakot mentés nélkül.
- **Készülékei** – az Eszközök lista, erre az ügyfélre szűrve.
- **Munkalapjai** – a Munkalapok lista az ügyfél összes lapjával.
- **Inaktívra állít** vagy **Újra aktív** – lásd a szabályoknál.

### Az ügyfélválasztó mező

Ez a mező áll a munkalapon, az árukiadáson, a rendelésen és az eszköz adatlapján. A felirata: `Kezdd el írni a nevet, telefont, ügyfélszámot…`

- Legalább két karaktert írj be, utána jelennek meg a találatok.
- Névre, mindkét telefonszámra, e-mail-címre, ügyfélszámra és utcára keres.
- Egyszerre legfeljebb tíz találatot mutat. Ha nem látod, akit keresel, írj be többet.
- Minden találat alatt ott a telefonszám, a teljes cím és az ügyfélszám.
- Az [[Enter]] az első találatot választja. Az [[Esc]] bezárja a listát.
- Ha nincs egyezés, ezt írja: „Nincs találat."

### Az ügyfél kártyája a lapon

A munkalapon, az árukiadáson és a rendelésen kártya mutatja a kiválasztott ügyfelet: **Név**, **Kategória**, **Cím**, **Telefonszám**, **E-mail**, és ha van, **Kedvezmény**. Az árukiadáson a kártya címe **Vevő**. Minden adat mellett kis másoló gomb áll.

## Szabályok, amiket a rendszer betart

- **Ügyfelet nem lehet törölni.** Az ügyfélre munkalapok hivatkoznak; ha eltűnne, a régi lapok érthetetlenné válnának. Törlés helyett **Inaktívra állít**: az ügyfél kikerül a napi listából és a választóból, de a régi lapjai mellett megmarad.
- Az inaktív ügyfél bármikor visszahozható: **Inaktívak** lista, megnyitod, **Újra aktív**.
- Az **Inaktívra állít** gomb azonnal átállít, nem kérdez rá. Előtte mentsd, amit az adatlapon módosítottál.
- A név nélküli ügyfél nem menthető.
- Az ügyfélválasztóban **a listából kell választani**. Ha a kiválasztás után átírod a nevet, a választás törlődik, és újra ki kell jelölnöd valakit. Így nem kerülhet munka véletlenül a korábbi ügyfélhez.
- Az ügyfélválasztó csak aktív ügyfelet kínál fel.
- Az ügyfélszám magától keletkezik, ha nem adsz meg sajátot: `U-` előtag és hatjegyű sorszám.
- Mindenhol a teljes cím látszik, nem csak a település: az ügyféllistában, a munkalapok listájában, a választóban és a keresőben is.
- Ha a csatolt fájlok közül bármelyik hibás (túl nagy, nem engedélyezett típus, sérült), semmi sem mentődik félig: előbb a fájlt kell rendbe tenni.
- Az érvénytelen lap nem számít bele az adatlap Munkalapok, Rendelések és Vásárlások fülének összegébe.
- A napi árukiadáson az ügyfél nem kötelező. Üresen hagyva a vevő „Napi árukiadás".

## Tippek

- A jobb alsó sarokban élő asszisztensnek írd be: „Vezess végig ezen az oldalon". Többlépcsős túrát indít, és sorban megmutatja az Ügyfelek oldal részeit.
- A teljes dokumentációt és a gyakori kérdéseket a **Súgó** menüpontban találod.
- Pultnál a leggyorsabb: az ügyfélválasztóba beírod a telefonszám végét, és [[Enter]].
- A másoló gombbal a név, a cím, a telefonszám és az e-mail egy kattintással a vágólapra kerül. Jól jön számlázásnál vagy futárrendelésnél.
- Visszatérő cégnél töltsd ki a **Kapcsolattartó** mezőt: a listában a cégnév alatt látszik, kivel szoktál beszélni.
- Ha az ügyfél több készüléket hoz, az adatlap **Készülékei** gombjával egyben látod mindet.
- A **Megjegyzés** fülre írd, amit a kollégának is tudnia kell: mikor érhető el, ki veheti át a készüléket.

## Ha valami nem megy

- **„A név kitöltése kötelező."** – a felső mező üres. Írd be a nevet, és ments újra.
- **Az ügyfél nem jelenik meg a választóban.** Valószínűleg inaktív. Nézd meg az Ügyfelek menüpont **Inaktívak** listájában, és állítsd **Újra aktív** állapotba.
- **A ceruza szürke az Ügyfél mező mellett.** Nincs kiválasztott ügyfél. Előbb válassz egyet a listából.
- **A település nem töltődik ki.** Ellenőrizd, hogy négy számjegyet írtál-e be. Külföldi címnél a mezőket kézzel töltsd ki.
- **„Az ügyfél mentve, de néhány fájl nem"** – az adatok elmentődtek, de egy fájlt nem sikerült tárolni. Nyisd meg újra az ügyfelet, és csatold ismét.
- **A fájlt nem fogadja el.** Fájlonként legfeljebb 25 MB tölthető fel, de a szerver ennél kisebb korlátot is szabhat. A pontos értéket a Csatolt fájlok fül alján olvasod.
- **„Nem sikerült kimásolni – jelöld ki, és Ctrl+C"** – a böngésző nem engedte a vágólap használatát. Jelöld ki a szöveget egérrel, és másold kézzel.
- **„Nincs jogosultságod ehhez."** – a fiókod nem fér hozzá a műhelyhez. Szólj az adminisztrátornak.

## GYIK

### Hogyan veszek fel új ügyfelet?

Az Ügyfelek menüpontban kattints az **Új ügyfél** gombra. Írd be a nevet, a többi mezőt töltsd ki, amennyire tudod, majd **Ügyfél létrehozása**. Csak a név kötelező.

### Fel tudok venni ügyfelet úgy, hogy közben munkalapot írok?

Igen. A munkalapon az **Ügyfél** mező mellett kattints a pluszjelre. Az új ügyfél ablaka a munkalap fölött nyílik. Mentés után a munkalap megmarad, és az új ügyfél már be van töltve.

### Miért nem tudom törölni az ügyfelet?

Mert munkalapok, rendelések és eszközök hivatkoznak rá. Törlés helyett az adatlap alján az **Inaktívra állít** gombot használd: az ügyfél eltűnik a napi listából, de a múltja megmarad.

### Hogyan hozok vissza egy inaktív ügyfelet?

Az Ügyfelek listában kattints az **Inaktívak** gombra, nyisd meg az ügyfelet, és az adatlap alján nyomd meg az **Újra aktív** gombot.

### Mi a különbség a Magánszemély, a Cég és a Közület között?

Ez az ügyfél típusa. A listában külön oszlopban látszik. Cégnél és közületnél érdemes az **Adószám** és a **Kapcsolattartó** mezőt is kitölteni.

### Hová írom a cég adószámát?

Az adatlap **Központi cím** lapfülén az **Adószám** mezőbe.

### Kétszer vettem fel ugyanazt az ügyfelet. Mit tegyek?

A rendszer nem von össze ügyfeleket. Döntsd el, melyiket használod tovább, a másikat pedig állítsd inaktívra. Az inaktív ügyfél régi lapjai megmaradnak.

### Mire tudok keresni az ügyféllistában?

Névre, mindkét telefonszámra, e-mail-címre, ügyfélszámra, településre, utcára és irányítószámra. Elég egy részlet is.

### Miért csak tíz találatot mutat az ügyfélválasztó?

Mert gépelés közbeni gyors választásra való, nem böngészésre. Ha nem látod a keresett ügyfelet, írj be még egy-két betűt vagy számjegyet.

### Beírtam a nevet a munkalapon, mégis azt írja, hogy válasszak ügyfelet. Miért?

A gépelés önmagában nem elég: a lenyíló listából rá kell kattintani az ügyfélre, vagy [[Enter]] billentyűvel az elsőt kiválasztani.

### Hogyan javítom az ügyfél telefonszámát, ha épp a munkalapja van nyitva?

Kattints a ceruzára az **Ügyfél** mező mellett, vagy a kártyán a **Szerkesztés** gombra. Javítsd a számot, és mentsd. A munkalap nyitva marad.

### Honnan jön az ügyfélszám?

Ha nem írsz be sajátot, mentéskor a rendszer ad egyet. Ha a régi nyilvántartásodból van saját számod, azt is beírhatod az **Ügyfélszám** mezőbe.

### Hol látom, mit javíttatott eddig az ügyfél?

Az adatlap alján a **Munkalapok** fülön. Egy sorra kattintva megnyílik a lap. A **Munkalapjai** gomb a teljes Munkalapok listát nyitja meg erre az ügyfélre szűrve.

### Hol látom, tartozik-e még az ügyfél?

Az adatlap **Munkalapok**, **Rendelések** és **Vásárlások** fülének tetején. Ha van kifizetetlen összeg, kiírja: „még fizetendő".

### Mi a különbség a Rendelések és a Vásárlások fül között?

A **Rendelések** az ügyfélnek megrendelt árut mutatja. A **Vásárlások** a napi árukiadásban a nevére rögzített pultos eladásokat.

### Más a szállítási cím, mint a számlázási. Hogyan adom meg?

Az adatlap tetején kattints a **Szállítási cím** lapfülre, vedd ki a pipát a **Megegyezik a központi címmel** jelölőből, és írd be a címet.

### Az ügyfélnek adott kedvezmény hol jelenik meg?

Az adatlap **Kedvezmény** mezőjébe írod be százalékban. A munkalapon az ügyfél kártyáján külön sorban látszik.

### Milyen fájlt csatolhatok az ügyfélhez?

Képet, PDF-et, szöveges és táblázatos dokumentumot, tömörített fájlt, hang- és videófájlt. Amit a rendszer nem enged, arról mentéskor üzenetet kapsz.

### Hogyan másolom ki gyorsan az ügyfél címét?

A munkalapon az ügyfél kártyáján a cím mellett kattints a kis másoló gombra. A gomb pipára vált, a cím a vágólapon van.

### Kell internet az irányítószám-kitöltéshez?

Nem. A magyar irányítószám-lista a rendszer része, a keresés a saját gépeden fut.

## Túra: ugyfelek

- `.sdh-fejlec__gombok [data-sdh-urlap="ugyfelek"]` | Új ügyfél | Ezzel veszel fel új ügyfelet felugró ablakban. Csak a név kötelező, a többit később is pótolhatod.
- `.sdh-kereso input[name="k"]` | Keresés | Ide írd a név, a telefonszám, az e-mail-cím, az ügyfélszám vagy a cím egy részét, majd nyomj Entert.
- `.sdh-kereso a[href*="inaktiv"]` | Inaktívak | Az ügyfelet nem törlöd, hanem inaktívra állítod. Itt találod meg őket, és innen hozhatod vissza.
- `.sdh-kereso__talalat` | Találatok száma | Megmutatja, hány ügyfél felel meg a keresésnek.
- `.sdh-tabla thead` | A lista oszlopai | Név, típus, telefon, e-mail, teljes cím és ügyfélszám. A lista névsorban áll.
- `.sdh-tabla tbody .sdh-tabla__nev a` | Az ügyfél neve | A névre kattintva megnyílik az adatlap: címek, megjegyzés, csatolt fájlok, munkalapok, rendelések és vásárlások.
- `.sdh-tabla tbody .sdh-tabla__cim` | Teljes cím | Mindenhol a teljes cím látszik: irányítószám, település, utca és házszám.
- `.sdh-lapozo` | Lapozó | Egy oldalon 25 ügyfél fér el. A többit a számokra kattintva éred el.
