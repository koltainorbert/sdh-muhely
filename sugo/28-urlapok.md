---
kulcs: urlapok
cim: Űrlapok beállítása – a fogaskerék
csoport: Rendszer
modul:
szakaszok: urlapok
kulcsszavak: fogaskerék, fogaskerek, beállítás az űrlapon, űrlap, űrlapok, mi látszódjon, elrejt, elrejtés, kikapcsol, bekapcsol, mező elrejtése, lapfül elrejtése, gomb elrejtése, állapotok kikapcsolása, választható állapot, bevizsgálás kikapcsolása, munkalap testreszabása, rendelés testreszabása, árukiadás testreszabása, számla tételei, tételek automatikusan, ne tegye rá a tételeket, kézi kitöltés, üres számla, pipálgatás, kipipálva, megjegyzés automatikusan, számla-előkészítő, számlázz.hu, marad frissítés után
osszefoglalo: A munkalap, a rendelés, a napi árukiadás és a két számla ablakának jobb felső sarkában álló fogaskerékkel állítod, mi látszódjon az űrlapon, és hogy a számlára magától kerüljenek-e a tételek; a beállítás frissítés után is megmarad.
---
## Mire való

A **fogaskerék** minden nagy űrlap jobb felső sarkában áll: a **Munkalap**, a **Rendelés**, a **Napi árukiadás**, a **Számlázz.hu számla** és a **Számla-előkészítő** ablakában. Mögötte kapcsolókkal állítod, mi látszódjon azon az űrlapon:

- **blokkok és mezők** – állapot, név, felelős, dátumok, összeg, kedvezmény és áfa, fizetés, előleg, bevizsgálási díj;
- **lapfülek** – Eszköz, Hibák, Szolgáltatások, Termékek, RMA / Címke, Megjegyzés, Belső megjegyzés;
- **+ Eszköz csatolása** – kikapcsolva a munkalapra nem csatolható újabb eszköz (a már csatoltak látszanak);
- **gombok** – Fizetés, Nyomtatás, Címke, Számlázz.hu számla, Számla-előkészítő;
- **választható állapotok** – melyik állapot jelenjen meg az állapotválasztóban;
- a két számlánál: **magától kerüljenek-e rá a munkalap tételei**, a tétel-megjegyzések (eszköz neve, IMEI) és a **Megjegyzés**.

Egyszer beállítod, és úgy marad: minden megnyitáskor, minden kollégánál, a rendszer frissítése után is.

## Hol találod

- **Az űrlapon:** nyisd meg a munkalapot (vagy rendelést, árukiadást, számlát), és kattints a jobb felső sarokban a **fogaskerékre**. Lenyílik a kapcsolók panelje.
- **A Beállításokban:** [Beállítások](modul:beallitasok) › Munkalap sor › **Űrlapok** lapfül. Itt mind az öt űrlap kapcsolói egymás alatt állnak, egy közös **Mentés** gombbal.

A fogaskereket csak az adminisztrátor látja és mentheti. A kollégák az űrlapot már a beállított formájában kapják, így nem tudják véletlenül átállítani.

## Lépésről lépésre: valami elrejtése az űrlapon

1. Nyisd meg az űrlapot, pl. egy munkalapot.
2. Kattints a jobb felső sarokban a **fogaskerékre**.
3. Kapcsold ki, ami nem kell (pl. **Bevizsgálási díj**, **Felelős**, **Hibák lapfül**). A változás **azonnal látszik** az űrlapon.
4. Kattints a **Mentés** gombra. A panel alján megjelenik: *Elmentve – így marad minden megnyitáskor.*
5. Kész: a következő megnyitáskor és minden kollégánál így nyílik az űrlap.

Ha meggondolod magad, a **Mind be** gomb mindent visszakapcsol (utána itt is **Mentés**).

## Lépésről lépésre: a számla tételei ne kerüljenek rá magától

Ha a számlát inkább kézzel töltöd ki, és nem akarod, hogy a kollégák a pipálgatásnál tévesszenek:

1. Nyisd meg egy munkalap **Számlázz.hu számla** (vagy **Számla-előkészítő**) ablakát.
2. Kattints a **fogaskerékre**.
3. Kapcsold ki: **A munkalap tételei magától a számlára**. Ha kell, a **Tétel-megjegyzés magától** és a **Megjegyzés mező magától** kapcsolót is.
4. **Mentés**.

Ettől kezdve a számla ablaka úgy nyílik, hogy **egy tétel sincs kipipálva**: te pipálod be, ami kell, vagy a **Kézi tétel** gombbal írod be a megnevezést és az árat. A két ablak (Számlázz.hu és előkészítő) **külön** állítható.

## A „munkalap tételei a számlán" kapcsoló az ablakban

A számla ablakában a tétellista fölött egy kapcsoló áll: **A munkalap tételei a számlán** (a nyomtatványnál: *a nyomtatványon*).

- Bekapcsolva a munkalap minden, még nem számlázott tétele kipipálva.
- Kikapcsolva egyik sincs kipipálva.
- Ha a több eszközös lapon egy eszközt választottál a **Mi kerül a számlára** sorban, a kapcsoló csak annak az eszköznek a tételeit pipálja.

Ez a kapcsoló **csak az épp nyitott ablakra** hat – az alapállást a fogaskerék adja, és az nem változik tőle. Így egy kattintással rá tudod tenni az összes tételt, ha egyszer mégis kell, anélkül hogy a beállítás elállítódna.

## Az állapotok kapcsolása

A munkalap, a rendelés és az árukiadás fogaskerekében külön csoport a **Választható állapotok**. Amit kikapcsolsz, az nem jelenik meg az állapotválasztóban ezen az űrlapfajtán.

- Az **alapállapot** (amivel az új lap indul) mindig választható – ennek a kapcsolója szürke.
- Ami a lapon **épp áll**, az mindig látszik, akkor is, ha kikapcsoltad – így régi lapon sem vész el az állapot.
- Az állapotok nevét, színét és sorrendjét továbbra is a [Beállítások](modul:beallitasok) › **Állapotok** lapfülén szerkeszted.

## Mezők és gombok

| Elem | Mit csinál |
|---|---|
| **fogaskerék** | Kinyitja / becsukja a kapcsolók paneljét (az [[Esc]] is becsukja). |
| kapcsoló | Ki: az elem nem látszik az űrlapon. Be: látszik. Azonnal hat, de csak a **Mentés** után marad meg. |
| **Mentés** | Elmenti az űrlap beállítását; minden megnyitáskor és minden kollégánál így nyílik. |
| **Mind be** | Minden elemet és állapotot visszakapcsol (utána **Mentés**). |
| **Bezárás** | Becsukja a panelt. |
| **Űrlapok** lapfül | A Beállításokban mind az öt űrlap kapcsolói, közös Mentéssel. |

## Szabályok, amiket a rendszer betart

- **Az elrejtett mező értéke nem vész el.** A mező nem látszik, de benne marad az űrlapban és mentődik; ha visszakapcsolod, ott van az adata.
- **A beállítás megmarad.** Egy beállításként tárolódik az adatbázisban; a rendszer frissítése nem nyúl hozzá, és a napi mentés is menti.
- **Csak az adminisztrátor állíthatja.** A kollégák nem látják a fogaskereket, és a mentést a rendszer is elutasítja tőlük.
- **Ha a nyitott lapfület elrejted,** az első látható lapfül nyílik helyette; ha egy lapfülsor minden füle rejtett, a sor is eltűnik.
- **A változásnapló nem kapcsolható ki**, ezért a Napló gombja nem szerepel a listában.
- Egy űrlap mentése **a többi űrlaphoz nem nyúl**: a munkalap beállítása nem változtatja a rendelését.
- Ami egy lapfajtán amúgy sincs (pl. bevizsgálási díj az árukiadáson), az a fogaskerékben sem szerepel.

## Ha valami nem megy

- **Nem látom a fogaskereket.** Csak adminisztrátorként látszik. Kollégaként kérd meg az admint, vagy lépj be admin fiókkal.
- **Elrejtettem valamit, de a következő megnyitáskor visszajött.** A **Mentés** gomb kimaradt: a kapcsoló csak élőben hat, mentés nélkül nem marad meg.
- **„A mentés nem sikerült (nincs kapcsolat)".** Megszakadt a kapcsolat a szerverrel; frissítsd az oldalt, és próbáld újra.
- **Eltűnt egy mező, és nem tudom, hol kapcsolom vissza.** A [Beállítások](modul:beallitasok) › **Űrlapok** lapfülén minden űrlap minden kapcsolója egyszerre látszik; vagy a fogaskerékben a **Mind be**.

## GYIK

### Hol van a fogaskerék?

Minden nagy űrlap jobb felső sarkában: munkalap, rendelés, napi árukiadás, Számlázz.hu számla, számla-előkészítő. Csak adminisztrátorként látszik.

### Megmarad a beállítás, ha frissül a rendszer?

Igen. A beállítás az adatbázisban tárolódik, a rendszer frissítése nem írja felül; a napi mentés is menti.

### Elvész az adat, ha egy mezőt elrejtek?

Nem. Az elrejtett mező benne marad az űrlapban és mentődik, csak nem látszik. Visszakapcsolva ott az adata.

### Hogyan érem el, hogy a számlára ne kerüljenek rá magától a tételek?

A számla ablakában a fogaskerékben kapcsold ki **A munkalap tételei magától a számlára** kapcsolót, és ments. Utána a tételek pipa nélkül nyílnak.

### Ha kikapcsoltam az automatikus tételeket, egyszer mégis mindet rá tudom tenni?

Igen: a számla ablakában a tétellista fölötti **A munkalap tételei a számlán** kapcsolóval mindet egyszerre kipipálod. Ez csak arra az ablakra hat, a beállítás marad.

### Külön állítható a Számlázz.hu számla és a számla-előkészítő?

Igen, a két ablaknak saját fogaskereke és saját beállítása van. Az egyik kikapcsolása a másikat nem érinti.

### Miért üres a számla Megjegyzés mezője?

Mert a fogaskerékben ki van kapcsolva a **Megjegyzés mező magától**. Kapcsold vissza, ha a „Munkalap: … · eszköz" szöveget kéred.

### Ki tudom venni az állapotválasztóból azokat az állapotokat, amiket nem használunk?

Igen: a fogaskerék **Választható állapotok** csoportjában kapcsold ki őket, és ments. Az alapállapot és a lapon épp álló állapot mindig látszik.

### Miért nem tudom kikapcsolni az egyik állapotot?

Az az új lap alapállapota: enélkül új lap nem indulhatna, ezért mindig választható. Másik alapállapotot a Beállítások › Állapotok lapfülén jelölhetsz ki.

### A kollégák át tudják állítani a fogaskereket?

Nem. A fogaskerék csak adminisztrátornak látszik, és a mentést a rendszer is csak adminnak engedi.

### Elrejtettem a Hibák lapfület, de a munkalap a Hibákon nyílt – mi történik?

Az első látható lapfül nyílik helyette. Ha egy lapfülsor minden fülét elrejted, a sor is eltűnik.

### A munkalapon beállítottam valamit – a rendelésen is így lesz?

Nem, mindhárom lapfajtának (munkalap, rendelés, napi árukiadás) saját beállítása van. Mindegyiken a saját fogaskerekével állítod.

### Hol látom egyszerre az összes űrlap beállítását?

A [Beállítások](modul:beallitasok) › Munkalap sor › **Űrlapok** lapfülén, egymás alatt mind az öttel.

### Ki tudom kapcsolni az „Eszköz csatolása" gombot?

Igen: a munkalap fogaskerekében (vagy Beállítások › Űrlapok › Munkalap) kapcsold ki a **+ Eszköz csatolása gomb** kapcsolót, és ments. A már csatolt eszközök továbbra is látszanak.

### Vissza tudom állítani az eredeti állapotot?

Igen: a fogaskerékben a **Mind be** minden elemet visszakapcsol; utána **Mentés**.
