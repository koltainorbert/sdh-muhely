---
cim: IMEI, sorozatszám, telefonszám: így találd meg pillanatok alatt bármelyik korábbi javítást
leiras: Hogyan építs olyan javítási nyilvántartást, amelyben a fél éves munkalap is azonnal előkerül IMEI-ből, telefonszámból vagy sorszámból? Gyakorlati tippek a felvételtől a keresésig.
kategoria: Munkalap és nyilvántartás
cimkek: [javítási nyilvántartás, IMEI, eszköztörténet, keresés]
datum: 2026-06-22
kulcsszo: javítási nyilvántartás
seo_cim: Javítási nyilvántartás: visszakeresés IMEI-ből és telefonszámból
---

**Röviden:**

- A visszakeresés minősége a felvételnél dől el: ha az IMEI és a telefonszám egységes formában kerül be, később bármelyikből megtalálod a javítást.
- A készülék legyen külön rekord, ne csak egy szövegsor a munkalapon. Így a készülék teljes előélete egy helyen látszik.
- A jó kereső felismeri, mit írtál be (IMEI, telefonszám, sorszám, név), és először abban keres.
- A visszatérő készüléknél a garancia állapota is azonnal látszódjon.

## Miért kerül elő ilyen gyakran a régi javítás?

Egy szervizben a régi javítás nem archívum, hanem napi kérdés. Az ügyfél visszahozza a készüléket, mert újra ugyanaz a hiba. A biztosító kéri a javítás igazolását. Egy kolléga azt kérdezi, milyen kijelzőt tettetek bele legutóbb. Az ügyfél nem emlékszik, mikor hozta be, csak arra, hogy „tavasszal”.

Ha ilyenkor percekig kell keresgélni, az ügyfél türelmetlen lesz, és a hiba is könnyebben becsúszik. Ha másodpercek alatt előkerül, az profizmust sugall, és a garanciális döntést is megkönnyíti.

## Az alap: a készülék mint önálló rekord

Sok nyilvántartásban a készülék csak egy szövegmező a munkalapon: „iPhone, fekete, törött kijelző”. Ebből később nem lehet megmondani, hogy ugyanaz a készülék járt-e már nálatok.

A jobb megoldás, ha a készülék külön rekord, saját azonosítókkal:

- gyártó és típus,
- IMEI (kétkártyás telefonnál a második is),
- gyári szám vagy sorozatszám,
- szín, tartozékok, átvételkori állapot.

A munkalap erre a rekordra hivatkozik. Így a készüléknek **előélete** lesz: az összes munkalap, amelyen szerepelt. A {termeknev} [eszköznyilvántartásában](/funkciok/eszkozok/) például a készülék adatlapján egy pillantással látszik, hány lapon szerepelt már, és garanciában van-e.

## Felvétel: így kerüljön be az adat

### IMEI

Az IMEI 15 számjegy. Gépeléskor könnyű elrontani, ezért érdemes:

- vonalkódolvasóval beolvasni a dobozról vagy a SIM-tálcáról, ha lehet,
- a `*#06#` kóddal a készülék kijelzőjéről leolvasni,
- olyan mezőbe írni, amely csak számjegyet fogad, és a beolvasó által beszúrt szóközöket kiszűri.

Ha a program ellenőrzi az IMEI ellenőrző számjegyét, az elgépelés már a felvételnél kiderül.

### Típusfelismerés

Az IMEI első nyolc számjegye a típusjel (TAC), ebből a gyártó és a típus meghatározható. Ha a program ezt magától kitölti, nem lesz a rendszerben ugyanaz a típus háromféleképpen leírva, ami a későbbi keresést és a statisztikát is javítja.

### Telefonszám

A telefonszám a leggyakoribb keresési kulcs, mert az ügyfél ezt tudja fejből. A gond az írásmóddal van: `06 30 123 4567`, `+36301234567`, `30/123-4567`. Ha a rendszer egységes alakra hozza a számot, mindegy, hogyan írod be keresésnél.

### Sorszám

A munkalap sorszáma az átvételi elismervényen van. Ha a lapon vonalkód vagy QR-kód is szerepel, a sorszám beolvasható, nem kell gépelni.

## Keresés: hogyan legyen gyors?

### Egyetlen keresőmező

A legjobb, ha nem kell kiválasztani, mire keresel. Egy jó [eszköznyilvántartás](/funkciok/eszkozok/) keresője gépelés közben felismeri, mit írtál be: 15 számjegynél IMEI-re keres, telefonszám-formánál az ügyfél telefonjában, sorszámnál a munkalapszámban, szövegnél a névben. A keresőmező alatt kiírja, mi szerint keresett, és ha nincs pontos találat, magától kiterjeszti a keresést mindenre.

### Részletek elég

Az ügyfél gyakran csak a szám végét mondja, vagy rosszul emlékszik a nevére. A keresőnek részletből is találnia kell: a telefonszám utolsó jegyeiből, a név egy részéből.

### Nagy adatnál is gyorsan

Egy évtizedes szervizben több százezer rekord is összegyűlhet. Érdemes rákérdezni, hogyan viselkedik a program nagy adatmennyiségnél. A {termeknev} listáit 300 000 rekordos próbaadaton mértük; az eredményeket a [gyakori kérdések](/gyik/#gyik-nagy-adatbazis-sebesseg) között közöltük.

## Visszatérő készülék: mit kell azonnal látni?

Amikor egy készülék visszajön, három kérdésre kell gyors válasz:

1. **Mikor és mivel járt itt legutóbb?** – a korábbi munkalapok, a cserélt alkatrészek, a munkadíj.
2. **Garanciában van-e még?** – a garancia a lezárás napjától számít, a lejárat legyen a lapon.
3. **Ugyanaz a hiba?** – a korábbi hibaleírás és a mostani összevetése.

Ha a rendszer ezt egy képernyőn mutatja, a pultos azonnal tud dönteni, és az új lapot garanciális visszatérésként rögzítheti, az eredeti laphoz kapcsolva. A garanciális ügyintézésről az [RMA és garancia](/funkciok/rma/) oldalon írunk.

## Ellenőrzőlista a jól kereshető nyilvántartáshoz

| Teendő | Miért fontos? |
|---|---|
| A készülék külön rekord, nem csak szöveg | Így van előélete |
| IMEI beolvasva vagy ellenőrizve | Az elgépelt IMEI-re soha nem találsz rá |
| Típus a TAC-ból kitöltve | Egységes elnevezés, jobb statisztika |
| Telefonszám egységes alakban | Bármilyen írásmóddal kereshető |
| Vonalkód vagy QR a munkalapon | A sorszám beolvasható |
| Garanciaidő a lapon | Visszatérésnél azonnal látszik |
| Átvételi fotó | A korábbi állapot is visszanézhető |

## Gyakori hibák

### Egy készülék, több rekord

Ha minden javításnál új készüléket veszel fel, ugyanaz a telefon háromszor szerepel, és az előélete három részre szakad. Felvételnél mindig nézd meg, nincs-e már a készülék a rendszerben; jó, ha a program figyelmeztet, ha a beírt IMEI már szerepel.

### Ügyfél név nélkül

A „Péter” vagy „telefonos srác” nevű ügyfelekből fél év múlva nem lesz találat. Legalább egy telefonszám mindig kerüljön be.

### Szabad szöveges hiba

A „nem megy” hibaleírásból nehéz statisztikát készíteni. Ha van gyakori hibák listája (például kijelzőcsere, akkumulátor, töltőcsatlakozó), a hibák egységesen kerülnek be, és később kereshetők.

## Ha nincs IMEI: laptop, tablet, kisgép

Nem minden készüléknek van IMEI-je. Laptopnál, Wi-Fi-s tabletnél, okosóránál vagy háztartási kisgépnél a gyári szám (sorozatszám) a fő azonosító. Ezt általában a készülék alján lévő címke, a rendszerinformáció vagy a doboz tartalmazza. Ugyanazok a szabályok érvényesek: egyszer, pontosan kerüljön be, és a készülékhez tartozzon, ne a munkalap szövegéhez. Ha a gyári szám olvashatatlan, írd be a típust és egy jól azonosítható jellemzőt (szín, matrica, sérülés), és tegyél a készülékről fotót.

## A gyári adatok pótlása

A sorozatszám, a gyártói garancia és a gyártás dátuma az IMEI-ből nem olvasható ki, ezeket a gyártó tudja. Két út van a pótlásukra: egy ingyenes ellenőrző oldal eredményét bemásolod, vagy egy előfizetett lekérdező szolgáltatásból egy gombnyomásra betöltöd. Mindkét esetben figyelj arra, hogy a gyártói adat felülírhatja a kézzel beírtat, ezért mentés előtt nézd át az adatlapot.

## Összegzés

A gyors visszakeresés nem a kereső tudásán múlik elsősorban, hanem azon, hogy az adat egységesen és pontosan kerül-e be. Ha a készülék önálló rekord IMEI-vel, a telefonszám egységes, és a munkalap kódja beolvasható, a fél éves javítás is pillanatok alatt előkerül.

A {termeknev} [demójában](/demo/) 10 000 mintarekord között kipróbálhatod a keresést IMEI-re, telefonszámra és sorszámra. A csomagokat az [Árak](/arak/) oldalon találod.
