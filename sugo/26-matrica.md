---
kulcs: matrica
cim: Címkenyomtatás (Brother) – RMA-matrica, rendelés-, termék- és szolgáltatás-címke
csoport: Napi munka
modul: matrica
szakaszok: matrica
kulcsszavak: matrica, címke, etikett, árcímke, polccímke, termékcímke, szolgáltatás címke, vonalkód címke, cikkszám, rma, rma matrica, brother, ql, ql-820nwb, ql-800, ql-700, p-touch, címkenyomtató, dk, dk-22205, dk-11209, 62 mm, 29x90, azonosító, készülék matrica, feloldó minta, zárkód, imei, nyomtatás, hálózat, ip-cím, 9100
osszefoglalo: Brother címkenyomtatóra nyomtatsz RMA-matricát a leadott készülékre (munkalap › Matrica), valamint termék- és szolgáltatás-címkét (az űrlapjuk Címke gombjával); a Beállításokban állítod, mi kerüljön rájuk.
---
## Mire való

Az **RMA-matrica** a leadott készülékre ragasztott azonosító címke: ránézésre látszik, kié a készülék, melyik munkalaphoz tartozik, mi a baja, és hogyan oldható fel.

Alapból ez kerül rá, ebben a sorrendben:

1. **Név** – az ügyfél neve,
2. **Munkalapszám**,
3. **Eszköz típusa és azonosítója** (modellszám),
4. **RMA** – rövid RMA-kód, mellette QR-kód az ügyféloldalra,
5. **Hiba**,
6. **Feloldó minta és feloldó kód** – a minta kis rajzként (a kezdőpötty bekarikázva) és számokkal,
7. **IMEI és azonosító** (sorozatszám vagy IMEI 2),
8. **Dátum** – a felvétel napja.

Bekapcsolható még: telefonszám, a szerviz neve, határidő, tartozékok, szín, átvételi állapot, megjegyzés, hányadik eszköz a lapon (pl. `2/3`), vonalkód a munkalapszámmal, külön QR-kód.

## Hol találod

- **Nyomtatás:** a munkalap ablakának alján a **Matrica** gomb (mentett, munkalapszámot kapott javítási lapon). Ugyanez a gomb ott van a **rendelés** ablakában is – ott a **Rendelés-címke** nyomtatódik, aminek saját tartalma van.
- **Beállítás:** **Beállítások › Címkenyomtatás** (a **Pénz és eladás** sorban) – nyomtató, címke, tartalom, előnézet, próbanyomat.
- **Termékcímke:** a termék ablakának alján a **Címke** gomb (mentett terméknél).
- **Szolgáltatás-címke:** a szolgáltatás ablakának alján a **Címke** gomb (mentett szolgáltatásnál).
- **Címkék menüpont:** az oldalmenü **Címkék** pontja. Innen indul az **Egyedi címke**, és itt látod egy táblázatban, melyik címke hol érhető el és mi áll rajta.
- **Egyedi címke:** a **Címkék** oldal **Egyedi címke** gombja – ide bármit beírhatsz, nem kell hozzá se munkalap, se termék.
- A modul a **Modulok** menüpontban kikapcsolható (**Címkenyomtatás (Brother)**); kikapcsolva nincs Matrica és Címke gomb.

## Termék- és szolgáltatás-címke

Ugyanazzal a Brother nyomtatóval és tekerccsel árcímkét, polccímkét vagy alkatrész-azonosítót is nyomtathatsz.

- **Termékcímke** – alapból: **megnevezés**, **bruttó ár** (nagy, félkövér), **cikkszám** és **vonalkód** középen, alatta a kód olvashatóan. A vonalkód a termék **Vonalkód** mezője; ha az üres, a **Cikkszám**. Bekapcsolható még: termékkód, gyári szám, kategória, nettó ár, mennyiségi egység, beszállító, leírás, a szerviz neve, dátum, QR-kód.
- **Szolgáltatás-címke** – alapból: **megnevezés**, **bruttó ár** (ha a szolgáltatásnak van felső ára, „tól–ig”, például `12 000 – 18 000 Ft`) és **kategória**. Bekapcsolható még: kód, nettó ár, mennyiségi egység, megjegyzés, a szerviz neve, dátum, vonalkód és QR-kód (a kódból).

Nyomtatás:

1. Nyisd meg a terméket vagy a szolgáltatást.
2. Kattints az ablak alján a **Címke** gombra. Megnyílik a címke előnézete.
3. Állítsd be a **Példány** számát a mellette lévő **−** és **+** gombbal, vagy írd be (legfeljebb 50 – például a polcon lévő darabszám).
4. Kattints a **Nyomtatás** gombra (böngészős módban a nyomtatás-ablakban válaszd a Brother nyomtatót).

Beállítás: **Beállítások › Címkenyomtatás**, felül a **Nyomtató és címke** doboz (közös), alatta a **RMA-matrica / Rendelés-címke / Termékcímke / Szolgáltatás-címke / Beszállítói tétel / Egyedi címke** fülek. Fülenként külön állítod a címke hosszát (folyamatos szalagon), a tájolást, a margót, a betűt, a példányszámot és hogy mi kerüljön rá – mindegyiknek saját élő előnézete és próbanyomata van. A nyomtató és a betett tekercs mindháromnál ugyanaz, hiszen egy nyomtatóban egyszerre egy tekercs van.

A címke nyomtatása a termék, illetve a szolgáltatás **Naplójába** kerül.

## Rendelés-címke

A rendelés ablakának **Matrica** gombja a **Rendelés-címkét** nyomtatja: ez kerül a megérkezett árura, hogy a pulton látszódjon, kinek jött és melyik laphoz tartozik.

Alapból ez van rajta: az **ügyfél neve**, a **lapszám**, a **telefonszám**, az **eszköz**, a **tételek** és a **dátum**. Bekapcsolható még: várható érkezés, beszállító, a beszállítói rendelés száma, előleg, végösszeg, megjegyzés, a szerviz neve, vonalkód és QR-kód.

**Ár alapból nincs rajta** – az ügyfél a bizonylaton látja az árat; a polcon álló dobozra nem kell. Ha mégis kell, a **Beállítások › Címkenyomtatás › Rendelés-címke** lapon kapcsold be a *Bruttó végösszeg* vagy a *Fizetett előleg* sort.

## Egyedi címke – amire hirtelen kell valamit ráírni

Nem minden címke tartozik rekordhoz: néha csak rá kell írni valamit egy dobozra, egy zacskóra vagy egy félretett alkatrészre, és kinyomtatni. Erre való az **Egyedi címke**.

1. Nyisd meg az oldalmenü **Címkék** pontját, és kattints az **Egyedi címke** gombra.
2. Írd be a szöveget. Négy sor van: az **1. sor** a nagy, félkövér felirat, a **2.** és a **3.** rendes méretű, a **4.** apró (ide való a figyelmeztetés, például „Nem adható ki!”). Az üres sor lemarad a címkéről.
3. Az előnézet gépelés közben követi, amit írsz.
4. Add meg a **Példány** számát, és kattints a **Nyomtatás** gombra.

Mi kerüljön rá azon felül, amit beírsz (a szerviz neve, a mai dátum, vonalkód vagy QR-kód az 1. sor értékéből), és melyik sor mekkora betűvel menjen: **Beállítások › Címkenyomtatás › Egyedi címke**. A nyomtató és a tekercs itt is ugyanaz, mint a többi címkénél.

Az egyedi címke **nem kerül naplóba**: nincs rekord, amihez tartozna.

## Lépésről lépésre: matrica nyomtatása

1. Nyisd meg a munkalapot.
2. Kattints az ablak alján a **Matrica** gombra. Megnyílik a Matrica ablak, benne minden eszköz matricájának előnézete.
3. Több eszközös lapon pipáld ki, melyik eszköz matricája kell (alapból mind).
4. Írd be a **Példány** számát, ha több kell ugyanabból.
5. Kattints a **Nyomtatás** gombra:
   - **Böngészős módban** megnyílik a böngésző nyomtatás-ablaka – válaszd a Brother nyomtatót, és nyomtass. A Matrica ablak tetején álló tipp megmondja, mit válassz: **Papírméret** (a címke Brother-neve, például „62mm” vagy „29mm x 90mm”), **Margók: Nincs**, **Méret: 100%**, a fejléc és lábléc **ki**.
   - **Hálózati módban** a matrica azonnal a nyomtatóra megy, ablak nélkül.
6. A **PDF megnyitása** gomb új lapon nyitja meg a matricát (mentéshez vagy kézi nyomtatáshoz).

A nyomtatás a lap **Naplójába** is bekerül.

## Lépésről lépésre: a nyomtató beállítása

1. Nyisd meg a **Beállítások › Címkenyomtatás** lapfület.
2. **Nyomtató típusa:** válaszd ki a Brother nyomtatót.
3. **Nyomtatási mód:**
   - **Böngésző (Brother illesztőprogram, PDF)** – minden Brother címkenyomtatóval megy (QL, PT, TD). A gépre telepítsd a Brother illesztőprogramot, és a nyomtató beállításaiban egyszer állítsd be a címke méretét (például 62 mm folyamatos).
   - **Közvetlenül hálózaton (QL, 9100-as port)** – a hálózatos QL nyomtatókkal (QL-580N, QL-710W, QL-720NW, QL-810W, QL-820NWB, QL-1060N, QL-1110NWB, QL-1115NWB). Írd be a nyomtató **IP-címét**; a **Port** marad `9100`. A nyomtatónak a routerben állandó címet adj, és a szervernek (a gépnek, amelyen a rendszer fut) el kell érnie.
4. **Címke / szalag:** a betett tekercs (például **DK-22205 – 62 mm folyamatos** vagy **DK-11209 – 62 × 29 mm**). Folyamatos szalagnál add meg **A matrica hossza** mezőt is.
5. **Tájolás:** **Álló** – a sorok a szalagon keresztben; **Fekvő** – a sorok a szalag hosszában.
6. Állítsd a **Margó**, a **Betűtípus** és az **Alap betűméret** mezőt; jobb oldalt az előnézet azonnal mutatja az eredményt.
7. Kattints a **Próbanyomat** gombra (kitalált adatokkal nyomtat), majd a **Mentés** gombra.

A **Nyomtató típusa**, a **Nyomtatási mód** és a **Címke / szalag** felül, a közös **Nyomtató és címke** dobozban van – ez a termék- és a szolgáltatás-címkére is érvényes. A hossz, a tájolás, a betű és a tartalom a fülek alatt, sablononként állítható.

## Lépésről lépésre: mi kerüljön a matricára

1. A **Mi kerüljön a matricára** dobozban minden adatnak egy sora van.
2. Pipáld be, ami kell – a ki nem pipált adat nem kerül rá.
3. A **felirat** mezőben a sor eleje áll (például `IMEI` → „IMEI: 3567…”); üresen csak az érték látszik.
4. A lenyílóban a betűméret (**kicsi**, **normál**, **nagy**, **óriás**…), az **F** pipa félkövérre állítja.
5. A **▲** és **▼** nyíllal állítsd a sorrendet.
6. **Az RMA a matricán:** **RMA-kód és QR-kód**, **Csak az RMA-kód** vagy **Csak a QR-kód**.
7. Figyeld az előnézetet, majd **Mentés**.

## Mezők és gombok

| Mező / gomb | Mire való |
|---|---|
| **Nyomtató típusa** | a Brother nyomtató; a hálózati módhoz a hálózatos QL-ek közül kell választani |
| **Nyomtatási mód** | böngészőből (illesztőprogrammal) vagy közvetlenül hálózaton |
| **A nyomtató IP-címe**, **Port** | hálózati módhoz |
| **Címke / szalag** | a betett DK-tekercs vagy TZe-szalag, vagy **Egyedi méret** |
| **A matrica hossza** | folyamatos szalagon egy matrica hossza mm-ben |
| **Tájolás**, **Margó** | a sorok iránya; a szélek üresen hagyott része |
| **Betűtípus**, **Alap betűméret** | a matrica betűje; a sorok mérete ehhez képest szorzódik |
| **Példányszám** | a Matrica ablak alapértéke |
| **Több eszközös lapon eszközönként külön matrica** | kikapcsolva csak a fő eszköz matricája készül |
| **Automatikus vágás matricánként** | hálózati módban a nyomtató matricánként vág |
| **Vékony keret a matrica szélén** | keret a matrica körül |
| **Próbanyomat** | kitalált adatokkal nyomtat a még el nem mentett beállítással |

## Szabályok, amiket a rendszer betart

- **Ha nem fér ki**, a rendszer arányosan kisebb betűvel írja (legfeljebb a felére). Ha így sem fér ki, az előnézet alatt szól: kapcsolj ki egy adatot, vagy válassz nagyobb címkét.
- A hosszú hiba legfeljebb három sorban, az eszköz két sorban áll; ami nem fér ki, „…”-tal végződik.
- A QR-kód az ügyféloldalra mutat (ugyanaz, mint a munkalap címkéjén); kikapcsolt Ügyfélüzenetek és RMA-oldal modulnál nincs RMA-kód és QR-kód.
- A **102 mm**-es címke csak a széles QL nyomtatókon (QL-1050/1060N/1100/1110NWB/1115NWB) fér el.
- A TZe-szalag és az egyedi méret csak böngészőből nyomtatható.
- A feloldó kód és minta a matricán olvasható – csak olyan készülékre tedd, amely a szervizben marad.

## Tippek

- A legtöbb szervizben a **62 mm folyamatos** szalag, **álló** tájolással, 40–50 mm hosszal a legjobb: minden alapadat elfér, a minta rajza is jól látszik.
- Kis készülékre (óra, fülhallgató) a **DK-11204 – 17 × 54 mm** címkét használd, és csak a nevet, a munkalapszámot és a dátumot hagyd bekapcsolva.
- Böngészős nyomtatásnál a nyomtatás-ablakban a **Margók: nincs** és a **100%-os méret** a jó beállítás.

## Ha valami nem megy

- **„A nyomtató nem érhető el”** (hálózati mód): be van kapcsolva? ugyanazon a hálózaton van, mint a szerver? jó az IP-cím? A nyomtató menüjében vagy a beállítási lapján nézd meg a címét.
- **A matrica üres vagy el van csúszva** (hálózati mód): a **Címke / szalag** egyezzen a betett tekerccsel. Ha mást teszel be, itt is írd át.
- **A Brother ablak: „A készülékben levő címke- vagy szalagtekercs nem egyezik az alkalmazásban kiválasztottal”** (böngészős mód): a beállított címke más, mint ami a nyomtatóban van. A Brother ablak kiírja a betett tekercset (például „62mm Folyamatos szalag”). Nyomj **Mégse**-t, a **Beállítások › Címkenyomtatás** lapon a **Címke / szalag** mezőben válaszd ugyanazt (a példában **DK-22205 – 62 mm folyamatos**), mentsd, és nyomtass újra. A nyomtatás-ablakban a **Papírméret** is ez legyen (a példában **62mm**) – a Matrica ablak kiírja, mit válassz.
- **A böngésző nem nyitja meg a nyomtatást:** a rendszer ilyenkor új lapon megnyitja a PDF-et – onnan nyomtass.
- **A PDF jó, de a nyomtató kicsinyíti** (böngészős mód): a Brother illesztőprogramban állítsd be ugyanazt a címkeméretet, és a nyomtatás-ablakban a 100%-os méretet.
- **Nincs Matrica gomb:** a lapnak még nincs munkalapszáma, vagy a lap árukiadás vagy rendelés, vagy a modul ki van kapcsolva.

## GYIK

### Milyen Brother nyomtató kell?

Böngészőből bármelyik Brother címkenyomtató jó (QL, PT, TD) a gyári illesztőprogrammal. Közvetlen, ablak nélküli nyomtatáshoz hálózatos QL kell (például QL-820NWB, QL-810W, QL-720NW).

### Mi az az RMA-kód a matricán?

Rövid, a laphoz tartozó egyedi kód (például `3F9A-12C4`). A mellette álló QR-kód az ügyféloldalra visz, ahol az ügyfél a javítás állapotát követi.

### Kinyomtathatom újra a matricát?

Igen, akárhányszor. Lezárt lapról is – a nyomtatás nem módosítja a lapot. Minden nyomtatás bekerül a Naplóba.

### Több eszköz van a lapon. Mindegyik kap matricát?

Igen, eszközönként egyet, a saját típusával, IMEI-jével, hibájával és feloldásával. A Matrica ablakban kipipálhatod, melyik kell.

### Ki kell kapcsolnom a feloldó kódot a matricán?

Ha a készülék a szervizben marad, maradhat – a kollégáknak a munkához kell. Ha nem szeretnéd, vedd ki a pipát a **Feloldó minta és feloldó kód** sorból.

### A nyomtató USB-n van. Mehet hálózati módban?

Nem: a hálózati mód a szervertől egyenesen a nyomtatóhoz megy. USB-s nyomtatóval a **Böngésző** módot használd – a Brother illesztőprogrammal minden USB-s típus nyomtat.

### Miért kisebb a betű a matricán, mint amit beállítottam?

Mert különben nem férne ki minden. A rendszer arányosan kicsinyít (legfeljebb a felére); az előnézet alatt látod, hány százalékra. Kapcsolj ki egy adatot, vagy válassz nagyobb címkét.

### Hogyan változtatom meg, mi álljon a sor elején?

A **Beállítások › Címkenyomtatás** › **Mi kerüljön a matricára** dobozban a sor **felirat** mezőjében. Üresen csak az érték látszik.

### Hol látom, mikor nyomtattak matricát egy laphoz?

A lap **Napló** gombjával: minden nyomtatás ott áll, időponttal és a kolléga nevével.

### Nyomtathatok árcímkét a termékekre?

Igen: nyisd meg a terméket, és kattints a **Címke** gombra. Mi kerüljön rá (ár, cikkszám, vonalkód…), azt a **Beállítások › Címkenyomtatás › Termékcímke** fülön állítod.

### Lehet más hosszú a termékcímke, mint az RMA-matrica?

Igen. Folyamatos szalagon a hossz, a tájolás és a betű fülenként külön állítható. A tekercs viszont közös: ha 62 mm-es szalag van a nyomtatóban, mindhárom címke 62 mm széles.

### Hogyan nyomtatok a P-touch szalagos nyomtatóra?

Válaszd a **Címke / szalag** mezőben a szalag szélességét (TZe 12–36 mm), és a böngészős módot. A Brother illesztőprogramban ugyanazt a szalagszélességet állítsd be.

### Hogyan állítom a példányszámot a címkeablakban?

A **Példány** melletti **−** és **+** gombbal, vagy írd be a számot. 1 és 50 között lehet. Az alapértéket címketípusonként a Beállítások › Címkenyomtatás lapon adod meg.

## Túra: matrica

- `.sdh-fejlec` | Címkék | Innen indul minden címke. A nyomtató és a tekercs a Beállításokban áll – itt az van, mit nyomtatsz.
- `[data-sdh-matrica-tipus="egyedi"]` | Egyedi címke | Amire hirtelen kell valamit ráírni: négy sor, amit beírsz. Nem kell hozzá se munkalap, se termék.
- `.sdh-tabla` | Milyen címkék vannak | Melyik címke honnan nyomtatható, és mi áll rajta. A jobb oldali gomb a tartalmához visz.
