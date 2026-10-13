---
kulcs: matrica
cim: RMA-matrica (Brother)
csoport: Napi munka
modul:
szakaszok: matrica
kulcsszavak: matrica, címke, etikett, rma, rma matrica, brother, ql, ql-820nwb, ql-800, ql-700, p-touch, címkenyomtató, dk, dk-22205, dk-11209, 62 mm, 29x90, azonosító, készülék matrica, feloldó minta, zárkód, imei, nyomtatás, hálózat, ip-cím, 9100
osszefoglalo: Az RMA-matrica a leadott készülékre kerülő azonosító címke Brother címkenyomtatóra – a munkalap Matrica gombjával nyomtatod, és a Beállításokban állítod, mi kerüljön rá.
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

- **Nyomtatás:** a munkalap ablakának alján a **Matrica** gomb (mentett, munkalapszámot kapott javítási lapon).
- **Beállítás:** **Beállítások › RMA-matrica** (a **Pénz és eladás** sorban) – nyomtató, címke, tartalom, előnézet, próbanyomat.
- A modul a **Modulok** menüpontban kikapcsolható (**RMA-matrica (Brother)**); kikapcsolva nincs Matrica gomb.

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

1. Nyisd meg a **Beállítások › RMA-matrica** lapfület.
2. **Nyomtató típusa:** válaszd ki a Brother nyomtatót.
3. **Nyomtatási mód:**
   - **Böngésző (Brother illesztőprogram, PDF)** – minden Brother címkenyomtatóval megy (QL, PT, TD). A gépre telepítsd a Brother illesztőprogramot, és a nyomtató beállításaiban egyszer állítsd be a címke méretét (például 62 mm folyamatos).
   - **Közvetlenül hálózaton (QL, 9100-as port)** – a hálózatos QL nyomtatókkal (QL-580N, QL-710W, QL-720NW, QL-810W, QL-820NWB, QL-1060N, QL-1110NWB, QL-1115NWB). Írd be a nyomtató **IP-címét**; a **Port** marad `9100`. A nyomtatónak a routerben állandó címet adj, és a szervernek (a gépnek, amelyen a rendszer fut) el kell érnie.
4. **Címke / szalag:** a betett tekercs (például **DK-22205 – 62 mm folyamatos** vagy **DK-11209 – 62 × 29 mm**). Folyamatos szalagnál add meg **A matrica hossza** mezőt is.
5. **Tájolás:** **Álló** – a sorok a szalagon keresztben; **Fekvő** – a sorok a szalag hosszában.
6. Állítsd a **Margó**, a **Betűtípus** és az **Alap betűméret** mezőt; jobb oldalt az előnézet azonnal mutatja az eredményt.
7. Kattints a **Próbanyomat** gombra (kitalált adatokkal nyomtat), majd a **Mentés** gombra.

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
- **A Brother ablak: „A készülékben levő címke- vagy szalagtekercs nem egyezik az alkalmazásban kiválasztottal”** (böngészős mód): a beállított címke más, mint ami a nyomtatóban van. A Brother ablak kiírja a betett tekercset (például „62mm Folyamatos szalag”). Nyomj **Mégse**-t, a **Beállítások › RMA-matrica** lapon a **Címke / szalag** mezőben válaszd ugyanazt (a példában **DK-22205 – 62 mm folyamatos**), mentsd, és nyomtass újra. A nyomtatás-ablakban a **Papírméret** is ez legyen (a példában **62mm**) – a Matrica ablak kiírja, mit válassz.
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

A **Beállítások › RMA-matrica** › **Mi kerüljön a matricára** dobozban a sor **felirat** mezőjében. Üresen csak az érték látszik.

### Hol látom, mikor nyomtattak matricát egy laphoz?

A lap **Napló** gombjával: minden nyomtatás ott áll, időponttal és a kolléga nevével.

### Hogyan nyomtatok a P-touch szalagos nyomtatóra?

Válaszd a **Címke / szalag** mezőben a szalag szélességét (TZe 12–36 mm), és a böngészős módot. A Brother illesztőprogramban ugyanazt a szalagszélességet állítsd be.
