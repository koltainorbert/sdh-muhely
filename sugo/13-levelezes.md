---
kulcs: levelezes
cim: Levelezés
csoport: Kapcsolat
modul: levelezes
szakaszok: levelezes
kulcsszavak: levél-feladat, feladat, teendő, beszállítói számla, Gigatel, levelezés, e-mail, levél, Gmail, postafiók, fiók, IMAP, SMTP, alkalmazásjelszó, mappa, címke, csatolmány, válasz, továbbítás, piszkozat, törlés, kuka, spam, archiválás, áthelyezés, új levél, értesítés, fontos levél, teendők, ügynök, rendező ügynök, jelszóval védett fiók, aláírás
osszefoglalo: A szerviz e-mail-fiókjai a rendszeren belül: olvasás, írás, válasz, csatolmány, mappák, új levél jelzése minden oldalon, és két ügynök – az egyik a fontos leveleket jelzi, a másik kérésre mappákba rendez.
---

## Mire való

A **Levelezés** menüpontban a szerviz e-mail-fiókjait kezeled anélkül, hogy kilépnél a rendszerből. Legfeljebb három fiók köthető be.

A levelek a levelezőszolgáltatónál (Gmail) maradnak. Amit itt csinálsz – olvasás, törlés, áthelyezés, küldés –, az a valódi postafiókban történik: a telefonodon is úgy fogod látni.

Amit a Levelezés tud:

- levelek olvasása, írása, válasz, továbbítás, piszkozat, csatolmányok;
- mappák (Gmail-címkék), áthelyezés, archiválás, spam, törlés, keresés;
- új levél jelzése a rendszer minden oldalán, fiókonként;
- **fontos-levél ügynök:** megmondja, melyik levélre kell gyorsan reagálni – csak jelez, mást nem tehet;
- **rendező ügynök:** kérésre mappákba rendezi a leveleket – csak azt, amit jóváhagysz.

## Hol találod

- **A menüpont:** az oldalmenüben [Levelezés](modul:levelezes) néven. Ha új levél jött, a neve mellett „N új” áll.
- **Az Áttekintésen:** a Levelezés csempéje az olvasatlan levelek számát mutatja, és kiemelve jelzi, ha azonnali teendő van.
- **A beállításai:** Beállítások → **Levelezés** lapfül (a „Kapcsolat és csapat” sorban).

Amíg nincs bekötött fiók, a menüpont csak egy hivatkozást mutat a Beállítások → Levelezés lapfülre.

## Lépésről lépésre: Gmail-fiók bekötése

A fiókot a Beállítások → **Levelezés** lapfülön kötöd be. A rendszer a fiókhoz **alkalmazásjelszóval** kapcsolódik.

> **Fontos:** a Google-fiók rendes jelszavát soha ne írd be a rendszerbe. Az alkalmazásjelszó egy külön, 16 betűs jelszó, amelyet a Google ad, és amelyet bármikor visszavonhatsz.

### 1. Alkalmazásjelszó kérése a Google-nél

1. Lépj be a Google-fiókba, és nyisd meg a **Biztonság** részt.
2. Kapcsold be a **Kétlépcsős azonosítás** lehetőséget. E nélkül alkalmazásjelszó nem kérhető.
3. Nyisd meg az **Alkalmazásjelszavak** oldalt (`myaccount.google.com/apppasswords`).
4. Hozz létre egy új jelszót, és adj neki egy felismerhető nevet (például „Műhely”).
5. Másold ki a kapott 16 betűt.

### 2. A fiók megadása a rendszerben

1. Nyisd meg a Beállítások → **Levelezés** lapfület. Három fiókhely van: **1. fiók**, **2. fiók**, **3. fiók**.
2. Az **E-mail-cím** mezőbe írd be a fiók címét (például `szerviz@pelda.hu`).
3. A **Megjelenő név (feladóként is)** mezőbe azt a nevet, amelyet a címzettek látnak.
4. Az **Alkalmazásjelszó** mezőbe illeszd be a 16 betűt. A szóközök nem számítanak.
5. Hagyd bepipálva: **A fiók be van kapcsolva.**
6. Kattints a lap alján a **Mentés** gombra.

### 3. A kapcsolat kipróbálása

1. Mentés után a jelszó mezője alatt megjelenik a **Kapcsolat ellenőrzése** gomb. Kattints rá.
2. Ha minden rendben, ezt látod: „A kapcsolat él: N mappa látszik, és a küldés is engedélyezett. (Levél nem ment ki.)”
3. Hiba esetén a gomb mellett megjelenik, hogy a bejövő (IMAP) vagy a kimenő (SMTP) oldal nem megy.
4. Nyisd meg a **Levelezés** menüpontot, és küldj egy próbalevelet a saját címedre. Nézd meg, megérkezik-e, és a telefonodon is látszik-e az Elküldött mappában.

> **Figyelem:** a **Kapcsolat ellenőrzése** csak a belépést próbálja ki, levelet nem küld. Hogy a levél tényleg megérkezik-e, azt a próbalevél mutatja meg. Éles használat előtt mindig küldj egyet.

A kapcsolat ellenőrzéséhez rendszergazdai jog kell.

### Nem Gmail-fiók

A **Szerver (Gmailnél nem kell átírni)** rész lenyitható. Gmailnél az alapértékek jók: bejövő `imap.gmail.com`, 993-as port, SSL/TLS; kimenő `smtp.gmail.com`, 465-ös port, SSL/TLS. Más szolgáltatónál itt írhatod át a szervert, a portot, a titkosítást és a felhasználónevet.

## Lépésről lépésre: levél olvasása

A [Levelezés](modul:levelezes) oldal három hasábból áll: balra a mappák, középen a levéllista, jobbra a megnyitott levél. Fölöttük a fiókok lapfülei.

1. Ha több fiókod van, kattints a kívánt fiók lapfülére. Egyszerre egy fiók mappái és levelei látszanak, a fiókok levelei nem keverednek.
2. Bal oldalt válassz mappát (például **Beérkezett**).
3. A listában kattints egy levélre. Jobb oldalt megnyílik.
4. A megnyitott levél olvasottá válik – a Gmailben is.

A levéllista egy sora: jelölőnégyzet, csillag, feladó, dátum, tárgy és kivonat. Az olvasatlan levél kiemelve áll; ↩ jelzi, ha már válaszoltál rá, gemkapocs, ha csatolmánya van.

Egy oldalon 25 levél fér el; a lista alján a nyilakkal lapozhatsz a régebbiekhez.

### A megnyitott levél

- Felül a tárgy és a műveleti gombok, alatta a **Feladó**, **Címzett**, **Másolat** és **Dátum**.
- Ha a feladó címe egy ügyfeledé, a neve mellett megjelenik az **Ügyfél: …** hivatkozás. Rákattintva az ügyfél adatlapja nyílik meg.
- A csatolmányok a levél fölött sorakoznak, névvel és mérettel. Kattintásra letöltődnek.
- A levél távoli képei alapból nem töltődnek be, hogy a feladó ne lássa, megnyitottad-e. A **Képek megjelenítése** gombbal betöltheted őket.

### Keresés

A fejlécben a **Keresés a megnyitott mappában…** mezőbe írd be a keresett szót, és kattints a **Keresés** gombra. A keresés a levelezőszerveren fut (Gmailnél a Gmail keresője). A találatok fölött a **törlés** gombbal lépsz vissza a teljes listához.

## Lépésről lépésre: levél írása és válasz

### Új levél

1. Kattints az **Új levél** gombra. Felugró ablak nyílik.
2. **Feladó:** több fióknál kattints rá, és válaszd ki, melyik címről menjen a levél.
3. **Címzett:** írd be a címet. Ahogy a mezőbe állsz, legördül, kinek szoktál írni: az **ügyfelek** e-mail-címei és a postafiók eddigi **feladói** – mindegyiknél a név, a cím és az, honnan való. Gépelve szűkül a lista (a névre és a címre is illeszkedik); a sorra kattintva vagy le-föl nyíllal és **Enterrel** választhatsz. Több címet vesszővel válassz el – a felajánlás mindig az utolsó, épp írt címre vonatkozik. Az **Esc** bezárja a listát; a cím kézzel is beírható.
4. **Másolat:** két mező – másolat (Cc) és titkos másolat (Bcc). Nem kötelező. Ezekben is legördülnek az ismert címek.
5. **Tárgy** és a levél szövege. A fiók aláírása magától bekerül.
6. Kattints a **Küldés** gombra. Siker esetén: „A levél elment.”

A **Mentés piszkozatként** gombbal a levél a Piszkozatok mappába kerül; onnan a **Folytatás** gombbal írhatod tovább.

### Válasz és továbbítás

A megnyitott levél fölött:

- **Válasz** – a feladónak megy. Az eredeti szöveg idézetként a levél alá kerül.
- **Válasz mindenkinek** – a feladónak, a többi címzett másolatban.
- **Továbbítás** – az eredeti levél a csatolmányaival együtt megy tovább. Amelyik csatolmány nem kell, annál vedd ki a pipát.

Válasznál a levél abból a fiókból megy, amelyikbe az eredeti érkezett; a feladó ilyenkor nem cserélhető.

### Csatolmány küldése

A levélíró alján kattints a **Fájl csatolása** gombra, és válassz egy vagy több fájlt. A nevük megjelenik a gomb mellett. A csatolmányok együtt legfeljebb 24 MB-ot tehetnek ki.

Ha a küldés nem sikerül, ezt látod: „A levél NEM ment el:”, utána a szolgáltató hibaüzenete.

## Lépésről lépésre: mappák, áthelyezés, törlés

### Mappák

A bal hasábban felül a rendszermappák állnak: **Beérkezett**, **Csillagozott**, **Fontos**, **Elküldött**, **Piszkozatok**, **Összes levél**, **Spam**, **Kuka**. Alattuk a **Címkék** felirat után a saját mappáid (a Gmailben ezek a címkék). A mappa neve mellett az olvasatlan levelek száma áll.

- **Új mappa:** kattints a lista alján az **Új mappa** gombra, írd be a nevét, majd **OK**.
- **Átnevezés:** nyisd meg a saját mappát, és a lista fölött kattints az **Átnevezés** gombra.
- **Mappa törlése:** ugyanott; a gomb felirata **Biztosan törlöd a mappát?** lesz, másodszor is rá kell kattintanod.

A rendszermappák nem nevezhetők át és nem törölhetők.

### Műveletek több levéllel

Pipáld ki a leveleket a listában (a lista fölötti négyzet az oldal összes levelét kijelöli), majd használd a gombsort:

| Gomb | Mit csinál |
|---|---|
| **Olvasott** / **Olvasatlan** | Átállítja a levelek olvasottságát. |
| **Archiválás** | A levél kikerül a Beérkezettből, az Összes levélben megmarad. |
| **Áthelyezés…** | Megnyitja az **Áthelyezés – hová?** ablakot, ott választasz mappát. |
| **Spam** / **Nem spam** | A Spam mappába teszi, illetve onnan visszahozza. |
| **Törlés** | A levél a Kukába kerül. |
| **Végleges törlés** | A Kukában, a Spamben és a Piszkozatokban. Megerősítést kér: **Biztosan? Nem vonható vissza**. |

Egyetlen levélnél ugyanezek a gombok a megnyitott levél fölött is ott vannak. Csillagozni a listában a csillagra kattintva lehet.

> **Figyelem:** véglegesen törölni csak a Kukából, a Spamből és a Piszkozatokból lehet. A végleges törlés nem vonható vissza – a Gmailből is eltűnik a levél.

A **Frissítés** gomb azonnal lekéri az új leveleket és a mappákat. A lista enélkül is követi, ami máshol (például a telefonodon) történt.

## Új levél jelzése

Az új levelekről a rendszer **minden oldalon** szól, nem csak a Levelezésben.

- **Az oldalmenüben** a Levelezés mellett „N új” jelenik meg. Ez nem az olvasatlan levelek száma: azt mutatja, mennyi levél jött, mióta utoljára megnézted a Beérkezett mappát.
- **Több fióknál** a menüpont alatt fiókonként külön sor áll, így látod, melyik címre jött a levél. Rákattintva az a fiók nyílik meg.
- **Felugró jelzés** a jobb alsó sarokban: a feladó, a tárgy, és több fióknál a fiók neve. Rákattintva megnyílik a levél. Egyszerre legfeljebb négy látszik; a többit a „+N további új levél” sor gyűjti össze. Ha az asszisztens figurája be van kapcsolva, a jelzések a figura **bal oldalán** sorakoznak, így a figura nem takarja ki őket; ha a figura épp megszólal, a buborékja a jelzések fölé emelkedik. Keskeny képernyőn (telefonon) a jelzések a figura fölé kerülnek.
- **Hangjelzés**, ha a Beállításokban be van kapcsolva.
- **A böngésző értesítése:** a Levelezés fejlécében megjelenő **Értesítések bekapcsolása** gombbal engedélyezheted. Így akkor is kapsz jelzést, ha másik ablakban dolgozol.

A jelzés mindenkinek külön számol. A Zaj besorolású levelekről (hírlevél, reklám) a rendszer nem szól.

## Jelszóval védett fiók

Egy fiókot külön jelszóval zárhatsz le a rendszeren belül – például ha a közös gépen nem mindenkinek kell látnia.

- A zárolt fiók lapfülén lakat látszik.
- Megnyitáskor ez fogad: „Ez a postafiók jelszóval védett.” Írd be a jelszót, és kattints a **Megnyitás** gombra.
- Amíg nincs megnyitva, a fiók levelei egyáltalán nem jutnak el a böngészőbe. Új levélnél is csak annyit tudsz meg, hogy levél érkezett a védett fiókba.
- Öt hibás próbálkozás után negyedóra szünet jön: „Túl sok hibás próbálkozás. 15 perc múlva próbáld újra.”
- A megnyitott fiók egy idő után magától újra zár (alapból 480 perc). Kézzel a fiók címe melletti **Zárás** gombbal zárhatod le.

A jelszót a Beállítások → Levelezés lapfülön, a fióknál adod meg.

## A fontos-levél ügynök

A fontos-levél ügynök minden beérkező levelet besorol, és megmondja, mire kell gyorsan reagálni.

> **Fontos:** ez az ügynök **csak jelez**. Nem ír, nem válaszol, nem töröl, nem mozgat levelet, és nem jelöli olvasottnak. A postafiókhoz nincs is hozzáférése.

A négy szint:

| Szint | Mit jelent | Jellemző példa |
|---|---|---|
| **Azonnal** | 1 órán belül reagálni kell. | Hatósági vagy jogi ügy, fiókbiztonság, fizetési figyelmeztetés, ügyfélpanasz, sürgősnek jelzett levél, kiemelt feladó. |
| **Ma** | Még aznap válaszolni kell. | Ismert ügyfél írt, válasz egy korábbi levélre, kérdés, ajánlatkérés. |
| **Ráér** | Nem sürgős. | Minden más személyes vagy üzleti levél. |
| **Zaj** | Nem kell vele foglalkozni. | Hírlevél, reklám, automatikus értesítés. |

Hol látod:

- **A listában** a tárgy előtt **Azonnal** vagy **Ma** jelzés áll. Fölé húzva az egeret megjelenik az indok.
- **A megnyitott levélben** sáv jelzi: „Erre 1 órán belül reagálni kell” vagy „Erre még ma válaszolni kell”, mellette az indok, és hogy szabály vagy AI döntött.
- **A Teendők nézetben:** a mappák fölött a **Teendők** sor a fiók összes olyan levelét mutatja, amire reagálni kell. Mellette az azonnali teendők száma.
- **A felugró jelzésben:** az azonnali levél jelzése „Azonnal reagálj” címkét kap, és nem tűnik el magától.

Ha végeztél a levéllel, kattints az **Elintézve** gombra a jelzősávban. A levél kikerül a teendők közül. Tévedés esetén a **Mégsem elintézett** gombbal visszateheted. Ha válaszolsz a levélre, az is elintézettnek számít.

Az ügynök szabályok alapján dolgozik; hogy mely feladók és kifejezések számítanak kiemeltnek, azt a Beállításokban adod meg.

## A levél-feladat ügynök

A harmadik ügynök azokkal a levelekkel foglalkozik, amelyek **minden nap megjönnek, és mindig ugyanaz a dolgunk velük**: a beszállító számlája, a webáruház visszaigazolása. Eddig ez kézi munka volt – és el is úszott: a levél elkeveredett, a számla nem lett kinyomtatva.

Mit csinál, ha egy levélre ráillik egy szabály:

1. **feladatot nyit** rá, és addig emlékeztet, amíg el nem intézed,
2. **mappába teszi** a levelet (ha nincs ilyen mappa, létrehozza),
3. **felkínálja a mellékelt PDF nyomtatását** (a számlát),
4. **kiolvassa a levél tételeit**, és amelyikhez telefonszám tartozik, arra **címkét** kínál.

Amit SOHA nem csinál: nem töröl levelet, nem válaszol, nem küld semmit, nem nyúl munkalaphoz, ügyfélhez, pénztárhoz. Nyomtatni is csak gombnyomásra nyomtat. A levél szövege adat: a benne álló „utasításoknak" nem engedelmeskedik – a szabály a **feladótól és a tárgytól** függ, nem attól, amit a levél kér.

### A feladatok

A Levelezés oldal tetején a **Feladatok** gomb mutatja, hány teendő van nyitva. Az ablakban feladatonként ott áll:

| Gomb | Mit csinál |
|---|---|
| **Levél megnyitása** | A levelezőben megnyitja magát a levelet. |
| **Számla nyomtatása (fájlnév)** | Új lapon megnyitja a mellékelt PDF-et – onnan nyomtatod. |
| **Címkék (n tétel)** | A címkenyomtató ablaka nyílik meg a tételekkel: kipipálod, melyikre kell, és nyomtatod. |
| **Elintézve** | Lezárja a feladatot. Ettől szűnik meg az emlékeztető. |

Amíg van nyitott feladat, a rendszer tízpercenként szól – pontosan azért, hogy a számla kinyomtatása ne maradjon el. Az **Elintézve** után nem szól többet; a lezárt feladat az **Az elintézettek is** gombbal nézhető vissza, és újranyitható.

### Szabályok

**Beállítások › Levelezés › Levél-feladat ügynök**. Egy szabály két részből áll:

- **Mikor:** a feladó tartalmazza…, a tárgy tartalmazza…, és (ha kell) legyen melléklet. Feltétel nélküli szabály nem fut – nehogy mindenre ráugorjon.
- **Mit:** melyik mappába kerüljön, emlékeztessen-e, ajánlja-e a melléklet nyomtatását, és melyik **tételolvasó** nézze át a levelet.

A tételolvasó az, ami egy adott beszállító levélformátumát ismeri. Ami csak az SDH-é (például a Gigatel), az a **Beállítások › SDH – belső** sorban van, és eladáskor nem megy ki.

## A rendező ügynök

A rendező ügynök kérésre mappákba rendezi a leveleket. Alapból **ki van kapcsolva**; a Beállítások → Levelezés lapfülön kapcsolod be, és ott adod meg, mit tehet.

> **Fontos:** a rendező ügynök **két dolgot tehet**: leveleket áthelyez mappába, és – ha engedélyezed – új mappát hoz létre. Semmi mást. Nem töröl, nem küld levelet, nem válaszol, nem jelöl olvasottnak. A saját jogain nem tud változtatni.

### Így használd

1. Nyisd meg azt a mappát, amelyben rendet akarsz tenni. Ha csak néhány levélről van szó, pipáld ki őket.
2. Kattints a fejlécben a **Rendező ügynök** gombra.
3. Az ablak tetején lista mutatja, mit tehet és mit nem ebben a fiókban.
4. Írd le, mit kérsz – például: „A hírleveleket és a reklámokat tedd a Hírlevelek mappába.”
5. Kattints a **Terv készítése** gombra.
6. Megjelenik a terv: csoportonként, hogy hány levél melyik mappába kerülne, néhány példával. Amelyik csoport nem kell, annál vedd ki a pipát.
7. Ha a tervhez új mappa kell, külön rész kér engedélyt: **Engedélyt kér: új mappa létrehozása**. A pipa alapból nincs bent – csak akkor jön létre a mappa, ha bepipálod.
8. Kattints a **Végrehajtás** gombra. A végén a „Kész:” lista mutatja, mi történt.

A **Vissza** gombbal átírhatod a kérést, a **Mégsem** gombbal kilépsz – ilyenkor semmi nem változik.

### Szabad szavas kérés vagy szűrő

- **Claude API-kulccsal** szabad szavakkal kérhetsz. Ilyenkor a levelek feladója, tárgya és kivonata külső szerverre megy elemzésre.
- **Kulcs nélkül** szűrővel dolgozik: **A feladó tartalmazza**, **A tárgy tartalmazza**, **Ebbe a mappába**.

### Amit tudnod kell

- Az ügynök a megnyitott mappa legújabb, legfeljebb 300 letöltött levelét látja – vagy csak azokat, amelyeket kijelöltél.
- Egy kérésben legfeljebb annyi levelet mozgat, amennyit a Beállításokban megadtál (alapból 100), és legfeljebb öt új mappát kérhet.
- A Piszkozatok, az Elküldött, a Csillagozott és a Fontos mappába soha nem tesz levelet. A Kukába és a Spambe csak akkor, ha ezt külön engedélyezted.
- A levelek szövegében álló „utasításokat” az ügynök nem követi; a nem engedélyezett lépéseket a rendszer kiszűri.

## A Levelezés lapfül a Beállításokban

A Beállítások → **Levelezés** lapfülön négy rész van: a fiókok, a fontos-levél ügynök, a rendező ügynök és az értesítések. A lap alján a **Mentés** gomb csak ezt a lapfület menti.

### Fiókok (1–3. fiók)

| Mező | Mire való |
|---|---|
| **E-mail-cím** | A fiók címe. |
| **Megjelenő név (feladóként is)** | Ez látszik a lapfülön, és ezt látják a címzettek feladóként. |
| **Alkalmazásjelszó** | A Google-től kapott 16 betű. Mentés után nem jelenik meg újra; csak akkor írd be, ha cserélnéd. |
| **Kapcsolat ellenőrzése** | Kipróbálja a bejövő és a kimenő kapcsolatot. Levelet nem küld. |
| **Jelszó törlése** | Mentéskor a rendszer elfelejti az alkalmazásjelszót. |
| **Külön jelszó a CRM-ben (nem kötelező)** | Ha megadod, a postafiókot csak az nyithatja meg, aki ezt beírja. |
| **Jelszavas védelem kikapcsolása** | Leveszi a fiókról a külön jelszót. |
| **Aláírás az új levelek végén** | Magától bekerül az új levelekbe és a válaszokba. |
| **A fiók be van kapcsolva.** | Pipa nélkül a fiók nem látszik a Levelezésben, de az adatai megmaradnak. |
| **Szerver (Gmailnél nem kell átírni)** | Felhasználónév, bejövő és kimenő szerver, port, titkosítás. |

### Fontos-levél ügynök

| Mező | Mire való |
|---|---|
| **Az ügynök be van kapcsolva.** | Kikapcsolva nincs besorolás és nincs Teendők-jelzés. |
| **Kiemelt feladók – mindig azonnali** | Soronként egy cím vagy domain. Tőlük minden levél Azonnal szintű. |
| **Partnerek – teendőnél azonnali, egyébként aznapi** | Soronként egy domain. |
| **Figyelt kifejezések – azonnali, ha a levélben szerepelnek** | Soronként egy kifejezés, például `garanciális csere`. |
| **Zajlista – ezekről soha nem szól** | Soronként egy cím vagy domain. |
| **Mesterséges intelligencia (Claude) is segítsen a besorolásban.** | Bekapcsolva a levelek feladója, tárgya és szövegének eleje külső szerverre megy elemzésre. Ez adattovábbítás: az adatkezelési tájékoztatóban szerepelnie kell. |
| **Claude API-kulcs** | Az AI-hoz és a rendező ügynök szabad szavas kéréseihez kell. Titkosítva tárolódik. |

Kikapcsolt AI mellett csak a szabályok döntenek, a levelek nem hagyják el a szervert. Az AI is csak besorol; amit a szabályok azonnalinak ítéltek, azt nem minősítheti le.

### Rendező ügynök

| Kapcsoló | Mire való |
|---|---|
| **A rendező ügynök be van kapcsolva.** | E nélkül a **Rendező ügynök** gomb meg sem jelenik. |
| **Leveleket áthelyezhet mappába.** | Az alapművelete. |
| **Új mappát létrehozhat (minden mappa előtt engedélyt kér).** | Kikapcsolva csak meglévő mappába rendezhet. |
| **A Kukába és a Spambe is tehet levelet.** | Alapból kikapcsolva: oda soha nem mozgat. |
| **Mozgatás előtt mindig megmutatja a tervet, és jóváhagyást kér.** | Kikapcsolva rögtön mozgat – új mappához akkor is engedélyt kér. |
| **Egy kérésben legfeljebb ennyi levelet mozgathat** | 1 és 300 között. |
| **Honnan mozgathat** | **Bármelyik megnyitott mappából** vagy **Csak a Beérkezett mappából**. |
| **Mely fiókokban dolgozhat** | Fiókonként külön pipa. Amelyik nincs bepipálva, abban az ügynök nem dolgozhat. |

> **Figyelem:** bekapcsolás után a **Mely fiókokban dolgozhat** résznél legalább egy fiókot be kell pipálnod, különben az ügynök sehol nem dolgozhat.

### Értesítések

| Mező | Mire való |
|---|---|
| **Új levelek keresése (másodpercenként)** | Milyen sűrűn nézzen új levelet a rendszer. 30 és 900 másodperc között; alapból 60. |
| **A jelszóval megnyitott fiók ennyi perc után újra zár** | 5 és 1440 perc között; alapból 480. |
| **Hangjelzés új levélnél.** | Rövid hang az új levél jelzése mellé. |

## Szabályok, amiket a rendszer betart

- Az alkalmazásjelszó és az API-kulcs titkosítva tárolódik, és nem jelenik meg újra.
- A zárolt fiók adatai a jelszó beírásáig nem jutnak el a böngészőbe.
- A levélben lévő programkód nem fut le; a csatolmány mindig letöltésként érkezik.
- A fontos-levél ügynök csak jelez. A rendező ügynök csak áthelyez, és engedéllyel mappát hoz létre.

## Tippek

- Az AI-asszisztens (a jobb alsó sarokban élő figura) a „Vezess végig ezen az oldalon” kérésre bemutatja a Levelezés oldalt.
- A legfontosabb feladóidat vedd fel a **Kiemelt feladók** közé; akinek a jelzései zavarnak, azt a **Zajlistára**.
- A rendező ügynököt először néhány kijelölt levélen próbáld ki.
- A teljes leírást és a gyakori kérdéseket a **Súgó** menüpontban találod.

## Ha valami nem megy

- **A Kapcsolat ellenőrzése hibát ír.** Ellenőrizd, hogy a Google-fiókban be van-e kapcsolva a kétlépcsős azonosítás, és hogy az alkalmazásjelszót – nem a rendes jelszót – írtad-e be. Kérj új alkalmazásjelszót, írd be, ments, és próbáld újra.
- **„Kapcsolati hiba – a legutóbbi állapot látszik.”** A szolgáltató most nem érhető el; kattints később a **Frissítés** gombra.
- **A levél nem ment el.** Olvasd el a hibaüzenetet. Gyakori ok a hibás cím vagy a túl nagy csatolmány.
- **„A rendező ügynök ebben a fiókban nem dolgozhat.”** A Beállításokban ez a fiók nincs bepipálva a **Mely fiókokban dolgozhat** résznél.

## GYIK

### Milyen jelszót írjak be a fiókhoz?

Alkalmazásjelszót – azt a 16 betűt, amelyet a Google-fiók **Alkalmazásjelszavak** oldalán kapsz. A Google-fiók rendes jelszavát soha ne írd be.

### Miért nem tudok alkalmazásjelszót kérni a Google-nél?

Valószínűleg nincs bekapcsolva a kétlépcsős azonosítás. Előbb azt kell bekapcsolnod a Google-fiók **Biztonság** részében.

### Ha itt törlök egy levelet, a telefonomról is eltűnik?

Igen. A levelek a Gmailben vannak, a rendszer azt mutatja, ami ott van. A törölt levél a Kukába kerül, ott a telefonodon is megtalálod.

### Vissza tudom hozni a törölt levelet?

A **Törlés** a Kukába teszi a levelet; onnan az **Áthelyezés…** gombbal visszateheted bármelyik mappába. A **Végleges törlés** után már nem.

### Miért nem „olvasatlan” számot mutat a menü?

Mert a menü azt jelzi, mennyi levél jött a legutóbbi megnézésed óta. Az olvasatlan levelek számát a mappa neve mellett, a fiók lapfülén és az Áttekintés csempéjén látod.

### Honnan tudom, melyik fiókba jött a levél?

Több fióknál az oldalmenüben a Levelezés alatt fiókonként külön sor jelenik meg, és a felugró jelzés is kiírja a fiók nevét.

### Hogyan küldök csatolmányt?

A levélíróban kattints a **Fájl csatolása** gombra, és válaszd ki a fájlokat. Együtt legfeljebb 24 MB lehet.

### Miért nem látszanak a képek a levélben?

A távoli képeket a rendszer alapból nem tölti be, mert azokból a feladó látná, hogy megnyitottad a levelet. A **Képek megjelenítése** gombbal betöltheted őket.

### Mit jelent az „Azonnal” jelzés egy levélen?

A fontos-levél ügynök szerint erre 1 órán belül reagálni kell. Az indokot a megnyitott levél jelzősávjában olvashatod.

### Tud az ügynök magától válaszolni vagy törölni?

Nem. A fontos-levél ügynök csak jelez. A rendező ügynök csak áthelyez, és engedéllyel mappát hoz létre. Egyik sem tud törölni, küldeni vagy válaszolni.

### Hogyan tüntetem el a levelet a Teendők közül?

Nyisd meg, és kattints az **Elintézve** gombra. Ha válaszolsz rá, az is elintézettnek számít.

### Az ügynök tévesen sorolt be egy levelet. Mit tehetek?

Ha fontosat hagyott ki, vedd fel a feladót a **Kiemelt feladók** közé. Ha feleslegesen szólt, tedd a **Zajlistára**. Mindkettő a Beállítások → Levelezés lapfülön van.

### Hogyan kapcsolom be a rendező ügynököt?

A Beállítások → Levelezés lapfülön pipáld be: **A rendező ügynök be van kapcsolva.**, és jelöld be, mely fiókokban dolgozhat. Mentés után megjelenik a **Rendező ügynök** gomb a Levelezésben.

### Létrehozhat az ügynök mappát a tudtom nélkül?

Nem. Minden új mappa előtt külön engedélyt kér, és a pipát neked kell betenned. A Beállításokban ezt a jogát teljesen ki is kapcsolhatod.

### Mi történik, ha a rendező ügynök rossz helyre tett leveleket?

Nyisd meg azt a mappát, jelöld ki a leveleket, és az **Áthelyezés…** gombbal tedd vissza őket. Az ügynök nem töröl, így levél nem veszhet el.

### Hogyan zárok le egy fiókot a kollégák elől?

A Beállítások → Levelezés lapfülön a fióknál töltsd ki a **Külön jelszó a CRM-ben** mezőt, és ments. Ezután a fiók csak a jelszóval nyitható meg.

### Elfelejtettem a fiók külön jelszavát. Mi a teendő?

A Beállítások → Levelezés lapfülön pipáld be a **Jelszavas védelem kikapcsolása** jelölőt, ments, és ha kell, adj meg új jelszót.

### Lecseréltem az alkalmazásjelszót a Google-nél. Hol írom át?

A Beállítások → Levelezés lapfülön írd be az újat az **Alkalmazásjelszó** mezőbe, ments, és kattints a **Kapcsolat ellenőrzése** gombra.

## Túra: levelezes

- `[data-l="uj"]` | Új levél | Innen indítasz új levelet. Felugró ablakban adod meg a címzettet, a tárgyat, a szöveget és a csatolmányokat.
- `[data-l-kereso]` | Keresés | A megnyitott mappában keres, a levelezőszolgáltató keresőjével.
- `[data-l="frissit"]` | Frissítés | Azonnal lekéri az új leveleket és a mappákat. A lista enélkül is magától frissül.
- `[data-l="rendezo"]` | Rendező ügynök | Kérésre mappákba rendezi a leveleket. Előbb tervet mutat, és csak azt hajtja végre, amit jóváhagysz.
- `[data-l-fulek]` | Fiókok | Minden e-mail-cím külön lapfül. Rajta az olvasatlan levelek és az azonnali teendők száma; a zárolt fióknál lakat.
- `[data-l-mappak]` | Mappák | Felül a Teendők, alatta a rendszermappák és a saját címkéid. Itt hozhatsz létre új mappát is.
- `[data-l-lista]` | Levéllista | A megnyitott mappa levelei. Kijelölés után olvasottá teheted, áthelyezheted vagy törölheted őket; alul lapozhatsz.
- `[data-l-olvaso]` | A megnyitott levél | Itt olvasod a levelet, innen válaszolsz, továbbítasz, és itt töltöd le a csatolmányokat.
